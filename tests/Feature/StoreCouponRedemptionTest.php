<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\StoreCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'marketing_consent' => true,
            'marketing_consent_updated_at' => now(),
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


    public function test_monthly_coupon_budget_allows_exact_cap_and_rejects_the_next_redemption(): void
    {
        SiteSetting::write('store_coupon_monthly_budget_rials', 15000);

        $user = User::create(['name' => 'Monthly Budget QA', 'mobile' => '09123456786', 'password' => 'secret']);
        $firstCoupon = StoreCoupon::create([
            'code' => 'BUDGETFIRST', 'type' => 'fixed', 'value' => 10000,
            'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
        ]);
        $firstOrder = Order::create([
            'user_id' => $user->id, 'subtotal_rials' => 100000, 'discount_rials' => 10000,
            'tax_rials' => 0, 'total_rials' => 90000, 'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $firstCoupon->code, 'coupon_redemption_pending' => true],
        ]);

        $service = app(StoreCouponRedemptionService::class);
        $service->redeemForPaidOrder($firstOrder);
        $firstOrder->update(['status' => 'paid', 'paid_at' => now()]);

        $secondCoupon = StoreCoupon::create([
            'code' => 'BUDGETSECOND', 'type' => 'fixed', 'value' => 6000,
            'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
        ]);
        $secondOrder = Order::create([
            'user_id' => $user->id, 'subtotal_rials' => 100000, 'discount_rials' => 6000,
            'tax_rials' => 0, 'total_rials' => 94000, 'status' => 'pending',
            'pricing_snapshot' => ['kind' => 'store', 'coupon_code' => $secondCoupon->code, 'coupon_redemption_pending' => true],
        ]);

        try {
            $service->redeemForPaidOrder($secondOrder);
            $this->fail('A redemption that exceeds the monthly budget must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('بودجه ماهانه', $exception->getMessage());
        }

        $this->assertSame(1, $firstCoupon->fresh()->used_count);
        $this->assertSame(0, $secondCoupon->fresh()->used_count);
        $this->assertTrue((bool) $secondOrder->fresh()->pricing_snapshot['coupon_redemption_pending']);
    }

    public function test_coupon_codes_are_normalized_when_saved(): void
    {
        $coupon = StoreCoupon::create(['code' => '  lowerCase-10 ', 'type' => 'percent', 'value' => 10]);

        $this->assertSame('LOWERCASE-10', $coupon->fresh()->code);
    }


    public function test_campaign_budget_is_rechecked_under_lock_when_smart_offer_is_redeemed(): void
    {
        $firstUser = User::create([
            'name' => 'Campaign Budget One', 'mobile' => '09123456791', 'password' => 'secret',
            'marketing_consent' => true, 'marketing_consent_updated_at' => now(),
        ]);
        $secondUser = User::create([
            'name' => 'Campaign Budget Two', 'mobile' => '09123456792', 'password' => 'secret',
            'marketing_consent' => true, 'marketing_consent_updated_at' => now(),
        ]);
        $category = StoreCategory::create(['name' => 'Campaign Budget', 'slug' => 'campaign-budget-category', 'is_active' => true]);
        $product = StoreProduct::create([
            'category_id' => $category->id, 'title' => 'Campaign Budget Product',
            'slug' => 'campaign-budget-product', 'sku' => 'CAMPAIGN-BUDGET-001',
            'type' => 'digital_file', 'price_rials' => 100000, 'cost_price_rials' => 50000,
            'status' => 'published', 'published_at' => now(),
        ]);
        $segmentId = DB::table('crm_customer_segments')->insertGetId([
            'name' => 'Campaign budget audience', 'filters' => json_encode([]),
            'created_by' => $firstUser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $campaignId = DB::table('crm_marketing_campaigns')->insertGetId([
            'name' => 'Capped campaign', 'channel' => 'in_app', 'status' => 'sent',
            'segment_id' => $segmentId, 'title' => 'Campaign', 'body' => 'Campaign offer',
            'created_by' => $firstUser->id, 'recipient_count' => 2, 'sent_at' => now(),
            'incentive_product_id' => $product->id, 'incentive_discount_percent' => 10,
            'incentive_valid_hours' => 48, 'budget_cap_rials' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $createOrder = function (User $user, string $code) use ($product, $campaignId): array {
            $coupon = StoreCoupon::create([
                'code' => $code, 'type' => 'fixed', 'value' => 10000,
                'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
                'ends_at' => now()->addDay(),
            ]);
            $assignment = StoreCouponAssignment::create([
                'coupon_id' => $coupon->id, 'campaign_id' => $campaignId,
                'user_id' => $user->id, 'product_id' => $product->id,
                'status' => 'offered', 'score' => 0,
                'reason_codes' => ['campaign_'.$campaignId],
                'offered_at' => now(), 'expires_at' => now()->addDay(),
            ]);
            $order = Order::create([
                'user_id' => $user->id, 'subtotal_rials' => 100000,
                'discount_rials' => 10000, 'tax_rials' => 0, 'total_rials' => 90000,
                'status' => 'pending',
                'pricing_snapshot' => [
                    'kind' => 'store', 'coupon_code' => $coupon->code,
                    'coupon_assignment_id' => $assignment->id,
                    'coupon_product_id' => $product->id, 'coupon_redemption_pending' => true,
                ],
            ]);
            StoreOrderItem::create([
                'order_id' => $order->id, 'product_id' => $product->id,
                'title_snapshot' => $product->title, 'quantity' => 1,
                'unit_price_rials' => 100000, 'discount_rials' => 10000,
                'tax_rials' => 0, 'total_rials' => 90000,
                'product_snapshot' => [
                    'cost_price_rials' => 50000,
                    'cost_snapshot_captured_at' => now()->toIso8601String(),
                ],
            ]);

            return [$coupon, $assignment, $order];
        };

        [$firstCoupon, $firstAssignment, $firstOrder] = $createOrder($firstUser, 'CAMPAIGNCAP1');
        app(StoreCouponRedemptionService::class)->redeemForPaidOrder($firstOrder);
        $firstOrder->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertSame('redeemed', $firstAssignment->fresh()->status);

        [$secondCoupon, $secondAssignment, $secondOrder] = $createOrder($secondUser, 'CAMPAIGNCAP2');
        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($secondOrder);
            $this->fail('Campaign redemptions must not exceed the configured campaign budget.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('سقف بودجه این کمپین', $exception->getMessage());
        }

        $this->assertSame(1, $firstCoupon->fresh()->used_count);
        $this->assertSame(0, $secondCoupon->fresh()->used_count);
        $this->assertSame('offered', $secondAssignment->fresh()->status);
        $this->assertTrue((bool) $secondOrder->fresh()->pricing_snapshot['coupon_redemption_pending']);
    }


    public function test_generic_redemption_cannot_spend_budget_reserved_by_active_smart_offers(): void
    {
        SiteSetting::write('store_coupon_monthly_budget_rials', 15000);

        $user = User::create(['name' => 'Reserved Budget QA', 'mobile' => '09123456787', 'password' => 'secret']);
        $reservedCoupon = StoreCoupon::create([
            'code' => 'BUDGETRESERVED', 'type' => 'fixed', 'value' => 10000,
            'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
        ]);
        StoreCouponAssignment::create([
            'coupon_id' => $reservedCoupon->id,
            'user_id' => $user->id,
            'product_id' => null,
            'status' => 'offered',
            'score' => 6,
            'reason_codes' => ['wishlist_interest'],
            'offered_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $checkoutCoupon = StoreCoupon::create([
            'code' => 'BUDGETCHECKOUT', 'type' => 'fixed', 'value' => 6000,
            'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 6000,
            'tax_rials' => 0,
            'total_rials' => 94000,
            'status' => 'pending',
            'pricing_snapshot' => [
                'kind' => 'store',
                'coupon_code' => $checkoutCoupon->code,
                'coupon_redemption_pending' => true,
            ],
        ]);

        try {
            app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);
            $this->fail('A generic coupon must not consume budget already reserved by an active smart offer.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('رزرو شده', $exception->getMessage());
        }

        $this->assertSame(0, $checkoutCoupon->fresh()->used_count);
        $this->assertTrue((bool) $order->fresh()->pricing_snapshot['coupon_redemption_pending']);
    }


}
