<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
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
        $this->assertNotEmpty($order->fresh()->pricing_snapshot['coupon_redeemed_at'] ?? null);
        $this->assertFalse($order->fresh()->pricing_snapshot['coupon_redemption_pending']);
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
    public function test_legacy_pending_orders_are_not_counted_twice(): void
    {
        $user = User::create([
            'name' => 'Legacy Coupon QA',
            'mobile' => '09123456782',
            'password' => 'secret',
        ]);
        $coupon = StoreCoupon::create([
            'code' => 'LEGACY10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 5,
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
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $coupon->code],
        ]);

        app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_pending_redemption_rejects_coupon_that_was_deactivated_before_payment(): void
    {
        $user = User::create(['name' => 'Inactive Coupon QA', 'mobile' => '09123456783', 'password' => 'secret']);
        $coupon = StoreCoupon::create(['code' => 'INACTIVE10', 'type' => 'percent', 'value' => 10, 'is_active' => false]);
        $order = Order::create([
            'user_id' => $user->id, 'subtotal_rials' => 100000, 'discount_rials' => 10000,
            'tax_rials' => 0, 'total_rials' => 90000, 'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $coupon->code, 'coupon_redemption_pending' => true],
        ]);

        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);
            $this->fail('An inactive coupon must not be redeemed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('اعتبار زمانی', $exception->getMessage());
        }

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertTrue((bool) $order->fresh()->pricing_snapshot['coupon_redemption_pending']);
    }

    public function test_pending_redemption_rechecks_minimum_subtotal_and_rejects_invalid_coupon_configuration(): void
    {
        $user = User::create(['name' => 'Coupon Rules QA', 'mobile' => '09123456784', 'password' => 'secret']);
        $coupon = StoreCoupon::create([
            'code' => 'MINLATER10', 'type' => 'percent', 'value' => 10,
            'min_subtotal_rials' => 200000, 'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'subtotal_rials' => 100000, 'discount_rials' => 10000,
            'tax_rials' => 0, 'total_rials' => 90000, 'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $coupon->code, 'coupon_redemption_pending' => true],
        ]);

        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);
            $this->fail('The minimum subtotal must be rechecked before redemption.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('حداقل مبلغ', $exception->getMessage());
        }

        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_new_smart_coupon_redemption_rejects_discount_not_allocated_to_its_target_item(): void
    {
        $user = User::create([
            'name' => 'Smart Coupon Allocation QA',
            'mobile' => '09123456785',
            'password' => 'secret',
        ]);
        $category = StoreCategory::create([
            'name' => 'دسته آزمون تخصیص',
            'slug' => 'smart-coupon-allocation-test',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول هدف آزمون',
            'slug' => 'smart-coupon-allocation-product',
            'sku' => 'SMART-ALLOC-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $coupon = StoreCoupon::create([
            'code' => 'SMARTALLOC12',
            'type' => 'fixed',
            'value' => 12000,
            'max_uses' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);
        $assignment = StoreCouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'offered',
            'score' => 6,
            'reason_codes' => ['cart_intent'],
            'offered_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 12000,
            'tax_rials' => 0,
            'total_rials' => 88000,
            'status' => 'pending',
            'pricing_snapshot' => [
                'kind' => 'store',
                'coupon_code' => $coupon->code,
                'coupon_redemption_pending' => true,
                'coupon_assignment_id' => $assignment->id,
                'coupon_product_id' => $product->id,
            ],
        ]);
        StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'product_snapshot' => [
                'cost_price_rials' => 50000,
                'cost_snapshot_captured_at' => now()->toIso8601String(),
            ],
        ]);

        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);
            $this->fail('A new smart coupon order must allocate its discount to the target item.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('تخصیص تخفیف', $exception->getMessage());
        }

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame('offered', $assignment->fresh()->status);
    }

    public function test_coupon_codes_are_normalized_when_saved(): void
    {
        $coupon = StoreCoupon::create(['code' => '  lowerCase-10 ', 'type' => 'percent', 'value' => 10]);

        $this->assertSame('LOWERCASE-10', $coupon->fresh()->code);
    }

}
