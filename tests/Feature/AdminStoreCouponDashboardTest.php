<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\StoreCategory;
use App\Models\StoreCoupon;
use App\Models\StoreCouponAssignment;
use App\Models\StoreProduct;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoreCouponDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Coupon Dashboard Admin',
            'email' => 'coupon-admin@example.test',
            'mobile' => '09151119991',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function product(): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته کوپن داشبورد',
            'slug' => 'coupon-dashboard-category',
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول داشبورد تخفیف',
            'slug' => 'coupon-dashboard-product',
            'sku' => 'COUPON-DASHBOARD-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_dashboard_shows_real_smart_assignments_and_coupon_cost_metrics(): void
    {
        $admin = $this->admin();
        $customer = User::create([
            'name' => 'مشتری پیشنهاد',
            'email' => 'coupon-customer@example.test',
            'mobile' => '09151119992',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $product = $this->product();
        $coupon = StoreCoupon::create([
            'code' => 'SMARTDASH10',
            'type' => 'fixed',
            'value' => 12000,
            'max_uses' => 1,
            'used_count' => 1,
            'is_active' => true,
        ]);
        $otherProduct = StoreProduct::create([
            'category_id' => $product->category_id,
            'title' => 'محصول دوم سبد',
            'slug' => 'coupon-dashboard-other-product',
            'sku' => 'COUPON-DASHBOARD-002',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 300000,
            'discount_rials' => 12000,
            'tax_rials' => 0,
            'total_rials' => 288000,
            'status' => 'paid',
            'paid_at' => now(),
            'pricing_snapshot' => ['kind' => 'store'],
        ]);
        StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'unit_price_rials' => 100000,
            'discount_rials' => 12000,
            'tax_rials' => 0,
            'total_rials' => 88000,
            'product_snapshot' => ['cost_price_rials' => 50000, 'cost_snapshot_captured_at' => now()->toIso8601String()],
        ]);
        StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $otherProduct->id,
            'title_snapshot' => $otherProduct->title,
            'sku_snapshot' => $otherProduct->sku,
            'quantity' => 2,
            'unit_price_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 200000,
            'product_snapshot' => [],
        ]);
        StoreCouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'redeemed',
            'score' => 6,
            'reason_codes' => ['wishlist_interest', 'cart_intent'],
            'offered_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'redeemed_at' => now(),
            'redeemed_order_id' => $order->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.store-coupons.index'))
            ->assertOk()
            ->assertSee('مرکز هوشمند کدهای تخفیف')
            ->assertSee('SMARTDASH10')
            ->assertSee('مشتری پیشنهاد')
            ->assertSee('استفاده موفق')
            ->assertSee('1,200', false)
            ->assertSee('8,800', false)
            ->assertSee('3,800', false)
            ->assertSee('سود ناخالص قابل محاسبه')
            ->assertSee('اقلام فاقد بهای تاریخی');
    }

    public function test_dashboard_does_not_estimate_profit_for_legacy_items_without_cost_snapshot(): void
    {
        $admin = $this->admin();
        $customer = User::create([
            'name' => 'مشتری سفارش قدیمی',
            'email' => 'coupon-legacy-cost@example.test',
            'mobile' => '09151119995',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $product = $this->product();
        $coupon = StoreCoupon::create([
            'code' => 'SMARTLEGACY',
            'type' => 'fixed',
            'value' => 5000,
            'max_uses' => 1,
            'used_count' => 1,
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 5000,
            'tax_rials' => 0,
            'total_rials' => 95000,
            'status' => 'paid',
            'paid_at' => now(),
            'pricing_snapshot' => ['kind' => 'store'],
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
            'product_snapshot' => [],
        ]);
        StoreCouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'redeemed',
            'score' => 5,
            'reason_codes' => ['cart_intent'],
            'offered_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'redeemed_at' => now()->subDay(),
            'redeemed_order_id' => $order->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.store-coupons.index'))
            ->assertOk()
            ->assertSee('اقلام فاقد بهای تاریخی')
            ->assertSee('سود این اقلام محاسبه یا تخمین زده نمی‌شود');
    }

    public function test_global_admin_can_save_live_smart_discount_rules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.store-coupons.settings'), [
            'score_threshold' => 6,
            'signal_window_days' => 21,
            'cooldown_days' => 10,
            'offer_valid_hours' => 36,
            'max_discount_percent' => 8,
            'abandonment_hours' => 3,
            'retention_days' => 90,
            'monthly_budget_rials' => 2500000,
        ])->assertRedirect(route('admin.store-coupons.index'));

        $this->assertSame(6, (int) SiteSetting::read('smart_discount_score_threshold', 5));
        $this->assertSame(21, (int) SiteSetting::read('smart_discount_signal_window_days', 14));
        $this->assertSame(8, (int) SiteSetting::read('smart_discount_max_discount_percent', 10));
        $this->assertSame(90, (int) SiteSetting::read('marketing_signal_retention_days', 30));
        $this->assertSame(2500000, (int) SiteSetting::read('store_coupon_monthly_budget_rials', 0));
    }

    public function test_signal_retention_cannot_be_shorter_than_the_analysis_window(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('admin.store-coupons.index'))
            ->put(route('admin.store-coupons.settings'), [
                'score_threshold' => 5,
                'signal_window_days' => 30,
                'cooldown_days' => 7,
                'offer_valid_hours' => 48,
                'max_discount_percent' => 10,
                'abandonment_hours' => 2,
                'retention_days' => 14,
            ])->assertRedirect(route('admin.store-coupons.index'))
            ->assertSessionHasErrors('retention_days');
    }

    public function test_admin_can_create_normalized_public_coupon_and_toggle_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.store-coupons.store'), [
            'code' => '  welcome_15 ',
            'type' => 'percent',
            'value' => 15,
            'max_uses' => 25,
            'description' => 'کمپین خوش‌آمدگویی',
        ])->assertRedirect(route('admin.store-coupons.index'));

        $coupon = StoreCoupon::query()->where('code', 'WELCOME_15')->firstOrFail();
        $this->assertSame(0, $coupon->used_count);
        $this->assertTrue($coupon->is_active);

        $this->patch(route('admin.store-coupons.toggle', $coupon->id))
            ->assertRedirect(route('admin.store-coupons.index'));
        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }

    public function test_public_coupon_creation_rejects_invalid_percentage_and_non_admin_access(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.store-coupons.index'))
            ->post(route('admin.store-coupons.store'), [
                'code' => 'INVALID150',
                'type' => 'percent',
                'value' => 150,
            ])->assertRedirect(route('admin.store-coupons.index'))
            ->assertSessionHasErrors('value');

        $employee = User::create([
            'name' => 'Coupon Dashboard Employee',
            'email' => 'coupon-employee@example.test',
            'mobile' => '09151119993',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $this->actingAs($employee)->get(route('admin.store-coupons.index'))->assertForbidden();
    }

    public function test_admin_can_revoke_an_unused_smart_offer_and_disable_its_coupon(): void
    {
        $admin = $this->admin();
        $customer = User::create([
            'name' => 'مشتری پیشنهاد قابل ابطال',
            'email' => 'coupon-revoke@example.test',
            'mobile' => '09151119994',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $product = $this->product();
        $coupon = StoreCoupon::create([
            'code' => 'SMARTREVOKE',
            'type' => 'fixed',
            'value' => 5000,
            'max_uses' => 1,
            'is_active' => true,
        ]);
        $assignment = StoreCouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'offered',
            'score' => 5,
            'reason_codes' => ['cart_abandoned'],
            'offered_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.store-coupons.assignments.revoke', $assignment->id))
            ->assertRedirect(route('admin.store-coupons.index'));

        $this->assertSame('revoked', $assignment->fresh()->status);
        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }
}
