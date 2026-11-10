<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCoupon;
use App\Models\User;
use App\Services\StoreCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StoreCouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_is_redeemed_once_when_a_paid_order_is_processed(): void
    {
        $user = User::create([
            'name' => 'Coupon QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);
        $coupon = StoreCoupon::create([
            'code' => 'PAYONCE10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 10000,
            'tax_rials' => 0,
            'total_rials' => 90000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $coupon->code, 'coupon_redemption_pending' => true],
        ]);

        $service = app(StoreCouponRedemptionService::class);
        $service->redeemForPaidOrder($order);
        $order->refresh();
        $service->redeemForPaidOrder($order->fresh());

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertNotEmpty($order->fresh()->pricing_snapshot['coupon_redeemed_at'] ?? null);\n        $this->assertFalse($order->fresh()->pricing_snapshot['coupon_redemption_pending']);
    }

    public function test_coupon_capacity_is_checked_at_redemption_not_order_creation(): void
    {
        $user = User::create([
            'name' => 'Coupon Capacity QA',
            'mobile' => '09123456780',
            'password' => 'secret',
        ]);
        $coupon = StoreCoupon::create([
            'code' => 'FULL10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 1,
            'used_count' => 1,
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 10000,
            'tax_rials' => 0,
            'total_rials' => 90000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $coupon->code, 'coupon_redemption_pending' => true],
        ]);

        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);
            $this->fail('A fully redeemed coupon must not be accepted for payment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ظرفیت استفاده', $exception->getMessage());
        }

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertEmpty($order->fresh()->pricing_snapshot['coupon_redeemed_at'] ?? null);
    }

    public function test_orders_without_a_coupon_do_not_change_coupon_usage(): void
    {
        $user = User::create([
            'name' => 'No Coupon QA',
            'mobile' => '09123456781',
            'password' => 'secret',
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store'],
        ]);

        app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);

        $this->assertArrayNotHasKey('coupon_redeemed_at', $order->fresh()->pricing_snapshot ?? []);
    }
}
