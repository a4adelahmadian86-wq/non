<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCoupon;
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

        // The order amount is immutable: never recalculate or silently alter its payable total here.
        $coupon->increment('used_count');

        $snapshot['coupon_redemption_pending'] = false;
        $snapshot['coupon_redeemed_at'] = now()->toIso8601String();
        $order->update(['pricing_snapshot' => $snapshot]);
    }
}
