<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreCoupon;
use RuntimeException;

class StoreCouponRedemptionService
{
    /**
     * Redeem the coupon only when its order is being marked paid.
     * The caller must hold a row lock on the order inside the payment transaction.
     */
    public function redeemForPaidOrder(Order $order): void
    {
        $snapshot = $order->pricing_snapshot ?? [];
        $code = trim((string) ($snapshot['coupon_code'] ?? ''));

        if ($code === '' || ! empty($snapshot['coupon_redeemed_at'])) {
            return;
        }

        $coupon = StoreCoupon::query()->where('code', $code)->lockForUpdate()->first();

        if (! $coupon) {
            throw new RuntimeException('کد تخفیف ثبت‌شده برای این سفارش دیگر در دسترس نیست؛ پرداخت انجام نشد.');
        }

        if ($coupon->max_uses !== null && (int) $coupon->used_count >= (int) $coupon->max_uses) {
            throw new RuntimeException('ظرفیت استفاده از این کد تخفیف پیش از پرداخت این سفارش تکمیل شده است.');
        }

        $coupon->increment('used_count');

        $snapshot['coupon_redeemed_at'] = now()->toIso8601String();
        $order->update(['pricing_snapshot' => $snapshot]);
    }
}
