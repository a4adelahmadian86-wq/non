<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreCoupon;
use App\Models\StoreCouponRedemption;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StoreCouponRedemptionService
{
    public function reserve(string $code, Order $order, int $subtotalRials): int
    {
        return DB::transaction(function () use ($code, $order, $subtotalRials): int {
            $existing = StoreCouponRedemption::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === 'reserved' && $existing->expires_at?->isFuture()) {
                    return (int) $existing->discount_rials;
                }
                if ($existing->status === 'redeemed') {
                    return (int) $existing->discount_rials;
                }

                throw new RuntimeException('رزرو کد تخفیف این سفارش دیگر معتبر نیست.');
            }

            $coupon = StoreCoupon::query()
                ->where('code', strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            if (! $coupon || ! $coupon->is_active
                || ($coupon->starts_at && $coupon->starts_at->isFuture())
                || ($coupon->ends_at && $coupon->ends_at->isPast())) {
                throw new RuntimeException('کد تخفیف معتبر یا فعال نیست.');
            }

            if ($coupon->max_uses !== null
                && ((int) $coupon->used_count + (int) $coupon->reserved_count) >= (int) $coupon->max_uses) {
                throw new RuntimeException('ظرفیت استفاده از این کد تمام شده است.');
            }

            if ($subtotalRials < (int) ($coupon->min_subtotal_rials ?? 0)) {
                throw new RuntimeException('مبلغ سبد خرید به حداقل لازم برای این کد تخفیف نمی‌رسد.');
            }

            $discount = $this->calculateDiscount($coupon, $subtotalRials);
            $coupon->update(['reserved_count' => (int) $coupon->reserved_count + 1]);

            $redemption = StoreCouponRedemption::create([
                'coupon_id' => $coupon->id,
                'order_id' => $order->id,
                'status' => 'reserved',
                'discount_rials' => $discount,
                'expires_at' => now()->addMinutes(30),
            ]);

            app(AuditEventService::class)->record(
                'store.coupon.reserved',
                (int) $order->user_id,
                null,
                'order',
                (int) $order->id,
                ['coupon_id' => $coupon->id, 'discount_rials' => $discount, 'expires_at' => $redemption->expires_at->toISOString()],
                'commerce'
            );

            return $discount;
        }, 3);
    }

    public function redeem(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $redemption = StoreCouponRedemption::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $redemption || $redemption->status === 'redeemed') {
                return;
            }

            if ($redemption->status !== 'reserved' || $redemption->expires_at?->isPast()) {
                throw new RuntimeException('مهلت رزرو کد تخفیف پایان یافته است؛ سفارش را دوباره ثبت کنید.');
            }

            $this->redeemLocked($redemption, $order, false);
        }, 3);
    }

    public function extendForGateway(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $redemption = StoreCouponRedemption::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $redemption) {
                return;
            }

            if ($redemption->status !== 'reserved' || $redemption->expires_at?->isPast()) {
                throw new RuntimeException('مهلت رزرو کد تخفیف پایان یافته است؛ سفارش را دوباره ثبت کنید.');
            }

            $redemption->update(['expires_at' => now()->addHours(12)]);
        }, 3);
    }

    public function restoreCheckoutExpiry(Order $order): void
    {
        StoreCouponRedemption::query()
            ->where('order_id', $order->id)
            ->where('status', 'reserved')
            ->update(['expires_at' => now()->addMinutes(30), 'updated_at' => now()]);
    }

    public function releaseExpired(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $ids = StoreCouponRedemption::query()
            ->where('status', 'reserved')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        $released = 0;

        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id): bool {
                $redemption = StoreCouponRedemption::query()->whereKey($id)->lockForUpdate()->first();

                if (! $redemption || $redemption->status !== 'reserved' || $redemption->expires_at?->isFuture()) {
                    return false;
                }

                $order = Order::query()->whereKey($redemption->order_id)->lockForUpdate()->first();

                if ($order && $order->status === 'paid') {
                    $this->redeemLocked($redemption, $order, true);
                    return true;
                }

                if ($order && $order->status === 'pending') {
                    $order->update(['status' => 'expired']);
                    Payment::query()
                        ->where('order_id', $order->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'expired', 'updated_at' => now()]);
                }

                $coupon = StoreCoupon::query()->whereKey($redemption->coupon_id)->lockForUpdate()->first();
                if ($coupon) {
                    $coupon->update(['reserved_count' => max(0, (int) $coupon->reserved_count - 1)]);
                }

                $redemption->update(['status' => 'released', 'released_at' => now()]);

                app(AuditEventService::class)->record(
                    'store.coupon.reservation_released',
                    $order ? (int) $order->user_id : null,
                    null,
                    'order',
                    (int) $redemption->order_id,
                    ['coupon_id' => $redemption->coupon_id, 'reason' => 'reservation_expired'],
                    'commerce_scheduler'
                );

                return true;
            }, 3);

            if ($changed) {
                $released++;
            }
        }

        return $released;
    }

    private function redeemLocked(StoreCouponRedemption $redemption, Order $order, bool $allowExpired): void
    {
        if ($redemption->status === 'redeemed') {
            return;
        }

        if ($redemption->status !== 'reserved'
            || (! $allowExpired && $redemption->expires_at?->isPast())) {
            throw new RuntimeException('رزرو کد تخفیف برای این سفارش معتبر نیست.');
        }

        $coupon = StoreCoupon::query()->whereKey($redemption->coupon_id)->lockForUpdate()->firstOrFail();
        $coupon->update([
            'reserved_count' => max(0, (int) $coupon->reserved_count - 1),
            'used_count' => (int) $coupon->used_count + 1,
        ]);

        $redemption->update(['status' => 'redeemed', 'redeemed_at' => now()]);

        app(AuditEventService::class)->record(
            'store.coupon.redeemed',
            (int) $order->user_id,
            null,
            'order',
            (int) $order->id,
            ['coupon_id' => $coupon->id, 'discount_rials' => (int) $redemption->discount_rials],
            'commerce'
        );
    }

    private function calculateDiscount(StoreCoupon $coupon, int $subtotalRials): int
    {
        if ($coupon->type === 'percent') {
            return (int) floor($subtotalRials * min(100, max(0, (int) $coupon->value)) / 100);
        }

        return min($subtotalRials, max(0, (int) $coupon->value));
    }
}
