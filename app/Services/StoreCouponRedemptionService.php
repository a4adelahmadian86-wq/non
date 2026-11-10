<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\StoreProduct;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreCouponRedemptionService
{
    /**
     * Redeem only inside the transaction that marks an order paid.
     * The caller must hold a row lock on the order; this method locks the coupon too.
     */
    public function redeemForPaidOrder(Order $order): void
    {
        $snapshot = $order->pricing_snapshot ?? [];
        $code = mb_strtoupper(trim((string) ($snapshot['coupon_code'] ?? '')));

        // Legacy orders without this marker already consumed their usage at checkout.
        if ($code === '' || empty($snapshot['coupon_redemption_pending']) || ! empty($snapshot['coupon_redeemed_at'])) {
            return;
        }

        $coupon = StoreCoupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->lockForUpdate()
            ->first();

        if (! $coupon) {
            throw new RuntimeException('کد تخفیف ثبت‌شده برای این سفارش دیگر در دسترس نیست؛ پرداخت انجام نشد.');
        }

        $assignment = null;
        $assignmentId = (int) ($snapshot['coupon_assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            $assignment = StoreCouponAssignment::query()->whereKey($assignmentId)->lockForUpdate()->first();
            if (! $assignment
                || (int) $assignment->coupon_id !== (int) $coupon->id
                || (int) $assignment->user_id !== (int) $order->user_id
                || $assignment->status !== 'offered'
                || ! $assignment->expires_at
                || $assignment->expires_at->lte(now())
                || ! $order->storeItems()->where('product_id', $assignment->product_id)->exists()
                || (int) ($snapshot['coupon_product_id'] ?? 0) !== (int) $assignment->product_id
                || $coupon->type !== 'fixed'
                || (int) $order->discount_rials !== (int) $coupon->value
                || Order::query()->where('user_id', $order->user_id)->where('status', 'paid')
                    ->where('id', '!=', $order->id)
                    ->whereHas('storeItems', fn ($items) => $items->where('product_id', $assignment->product_id))->exists()) {
                throw new RuntimeException('این کد اختصاصی برای این سفارش معتبر نیست یا مهلت آن پایان یافته است.');
            }
        }

        if ($assignment && $assignment->campaign_id) {
            // Serialize all redemptions for this campaign on its parent row.
            // This keeps simultaneous orders from exceeding the campaign's own cap.
            $campaign = DB::table('crm_marketing_campaigns')
                ->where('id', $assignment->campaign_id)
                ->lockForUpdate()
                ->first();
            if (! $campaign || $campaign->status !== 'sent' || (int) $campaign->incentive_product_id !== (int) $assignment->product_id || (int) ($campaign->budget_cap_rials ?? 0) <= 0) {
                throw new RuntimeException('بودجه کمپین این پیشنهاد دیگر معتبر نیست.');
            }

            $campaignSpent = DB::table('store_coupon_assignments as assignments')
                ->join('orders', 'orders.id', '=', 'assignments.redeemed_order_id')
                ->join('store_coupons', 'store_coupons.id', '=', 'assignments.coupon_id')
                ->where('assignments.campaign_id', $assignment->campaign_id)
                ->where('assignments.status', 'redeemed')
                ->where('orders.status', 'paid')
                ->whereNotNull('orders.paid_at')
                ->where('orders.id', '!=', $order->id)
                ->sum('store_coupons.value');

            if ((int) $campaignSpent + (int) $coupon->value > (int) $campaign->budget_cap_rials) {
                throw new RuntimeException('سقف بودجه این کمپین پیش از پرداخت سفارش تکمیل شده است.');
            }
        }

        if ($assignment) {
            $product = StoreProduct::query()->find($assignment->product_id);
            $orderItems = $order->storeItems()->get();
            $orderItem = $orderItems->firstWhere('product_id', (int) $assignment->product_id);
            $hasFinancialSnapshot = $orderItem
                && ! empty(($orderItem->product_snapshot ?? [])['cost_snapshot_captured_at']);

            // New checkout snapshots must show the smart discount on the target
            // line only, with item discounts reconciling to the order discount.
            // Keep legacy pending orders compatible with their older item schema.
            if ($hasFinancialSnapshot
                && ((int) $orderItem->discount_rials !== (int) $coupon->value
                    || (int) $orderItems->sum('discount_rials') !== (int) $order->discount_rials)) {
                throw new RuntimeException('تخصیص تخفیف در ردیف‌های سفارش با کد اختصاصی مطابقت ندارد؛ پرداخت انجام نشد.');
            }

            $safeDiscountCap = $product && $orderItem
                ? app(StoreCouponMarginPolicy::class)->maximumDiscount($product, (int) $orderItem->unit_price_rials, (int) $orderItem->quantity)
                : 0;
            if ((int) $coupon->value > $safeDiscountCap) {
                throw new RuntimeException('حاشیه سود محصول پیش از پرداخت تغییر کرده و این کد دیگر قابل استفاده نیست.');
            }
        }

        $now = now();
        if (! $coupon->is_active
            || ($coupon->starts_at && $now->lt($coupon->starts_at))
            || ($coupon->ends_at && $now->gt($coupon->ends_at))) {
            throw new RuntimeException('اعتبار زمانی یا وضعیت این کد تخفیف تغییر کرده است؛ سفارش را دوباره بررسی کنید.');
        }

        $subtotal = max(0, (int) ($order->subtotal_rials ?? 0));
        if ($subtotal <= 0 || ($coupon->min_subtotal_rials !== null && $subtotal < (int) $coupon->min_subtotal_rials)) {
            throw new RuntimeException('حداقل مبلغ لازم برای استفاده از این کد تخفیف در سفارش رعایت نشده است.');
        }

        $value = (int) $coupon->value;
        if ($value <= 0 || ! in_array($coupon->type, ['percent', 'fixed'], true)
            || ($coupon->type === 'percent' && $value > 100)) {
            throw new RuntimeException('تنظیمات این کد تخفیف معتبر نیست؛ پرداخت انجام نشد.');
        }

        if ($coupon->max_uses !== null && (int) $coupon->used_count >= (int) $coupon->max_uses) {
            throw new RuntimeException('ظرفیت استفاده از این کد تخفیف پیش از پرداخت این سفارش تکمیل شده است.');
        }

        // The same locked policy used by offer issuance includes active offers
        // as reservations and excludes this assignment as it moves from reserved
        // to consumed budget inside the current payment transaction.
        if (! app(StoreCouponBudgetPolicy::class)->allows(
            max(0, (int) $order->discount_rials),
            $assignment?->id
        )) {
            throw new RuntimeException('بودجه ماهانه تخفیف‌های فراست تکمیل شده یا برای پیشنهادهای فعال رزرو شده است؛ این کد در این دوره قابل مصرف نیست.');
        }

        // The order amount is immutable: never recalculate or silently alter its payable total here.
        $coupon->increment('used_count');

        $snapshot['coupon_redemption_pending'] = false;
        $snapshot['coupon_redeemed_at'] = now()->toIso8601String();
        $order->update(['pricing_snapshot' => $snapshot]);

        if ($assignment) {
            $assignment->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'redeemed_order_id' => $order->id,
            ]);
        }
    }
}
