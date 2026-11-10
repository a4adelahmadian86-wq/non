<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\StoreCategory;
use App\Models\StoreCouponAssignment;
use App\Models\StoreCoupon;
use App\Models\StoreOrderItem;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\MarketingSignalService;
use App\Services\StoreCartAbandonmentService;
use App\Services\SmartDiscountMarketingService;
use App\Services\StoreCouponRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SmartDiscountMarketingServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function user(bool $consent = true): User
    {
        $mobile = '09151113'.str_pad((string) ++$this->sequence, 3, '0', STR_PAD_LEFT);

        return User::create([
            'name' => 'Smart Marketing QA',
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => User::ROLE_EMPLOYEE,
            'marketing_consent' => $consent,
        ]);
    }

    private function product(?int $cost = 60000, int $margin = 10000): StoreProduct
    {
        $slug = 'smart-marketing-product-'.$this->sequence.'-'.random_int(1000, 9999);
        $category = StoreCategory::create([
            'name' => 'دسته پیشنهاد هوشمند '.$slug,
            'slug' => $slug,
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول پیشنهاد هوشمند',
            'slug' => $slug,
            'sku' => strtoupper('SMART-'.$this->sequence.'-'.random_int(1000, 9999)),
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => $cost,
            'marketing_min_margin_rials' => $margin,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    private function createQualifiedSignals(User $user, StoreProduct $product): void
    {
        $signals = app(MarketingSignalService::class);
        $signals->record($user, 'product_viewed', $product->id);
        Carbon::setTestNow(now()->addMinutes(2));
        $signals->record($user, 'product_viewed', $product->id);
        $signals->record($user, 'wishlist_added', $product->id);
    }

    public function test_live_score_threshold_prevents_offer_until_the_new_threshold_is_met(): void
    {
        SiteSetting::write('smart_discount_score_threshold', 6);
        $user = $this->user();
        $product = $this->product();

        $this->createQualifiedSignals($user, $product);

        $this->assertDatabaseCount('store_coupon_assignments', 0);
    }

    public function test_a_single_product_view_never_generates_a_discount_offer(): void
    {
        $user = $this->user();
        $product = $this->product();

        app(MarketingSignalService::class)->record($user, 'product_viewed', $product->id);

        $this->assertDatabaseCount('store_coupon_assignments', 0);
    }

    public function test_repeated_interest_generates_a_user_bound_product_coupon_with_margin_protection(): void
    {
        $user = $this->user();
        $product = $this->product(60000, 10000);

        $this->createQualifiedSignals($user, $product);

        $assignment = StoreCouponAssignment::query()->with(['coupon', 'notification'])->firstOrFail();

        $this->assertSame($user->id, $assignment->user_id);
        $this->assertSame($product->id, $assignment->product_id);
        $this->assertSame('offered', $assignment->status);
        $this->assertContains('repeated_product_views', $assignment->reason_codes);
        $this->assertContains('wishlist_interest', $assignment->reason_codes);
        $this->assertSame(10000, $assignment->coupon->value);
        $this->assertSame(1, $assignment->coupon->max_uses);
        $this->assertNotNull($assignment->notification_id);
        $this->assertStringContainsString($assignment->coupon->code, $assignment->notification->body);
        $this->assertDatabaseHas('farast_notifications', [
            'id' => $assignment->notification_id,
            'user_id' => $user->id,
            'type' => 'marketing_discount_offer',
        ]);

        $this->assertLessThanOrEqual(
            $product->price_rials - $product->cost_price_rials - $product->marketing_min_margin_rials,
            $assignment->coupon->value
        );
    }


    public function test_engine_reduces_incentive_after_strong_product_conversion_history(): void
    {
        $user = $this->user();
        $product = $this->product(60000, 10000);

        for ($i = 0; $i < 10; $i++) {
            $historyUser = $this->user(false);
            $coupon = StoreCoupon::create([
                'code' => 'HISTORY'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'type' => 'fixed',
                'value' => 10000,
                'max_uses' => 1,
                'used_count' => $i < 4 ? 1 : 0,
                'is_active' => true,
            ]);
            StoreCouponAssignment::create([
                'coupon_id' => $coupon->id,
                'user_id' => $historyUser->id,
                'product_id' => $product->id,
                'status' => $i < 4 ? 'redeemed' : 'offered',
                'score' => 6,
                'reason_codes' => ['wishlist_interest'],
                'offered_at' => now()->subDays(5),
                'expires_at' => now()->addDay(),
                'redeemed_at' => $i < 4 ? now()->subDays(4) : null,
            ]);
        }

        $this->createQualifiedSignals($user, $product);

        $assignment = StoreCouponAssignment::query()
            ->where('user_id', $user->id)
            ->with('coupon')
            ->firstOrFail();

        $this->assertSame(7500, $assignment->coupon->value);
    }

    public function test_engine_keeps_a_five_percent_default_margin_when_product_floor_is_not_configured(): void
    {
        $user = $this->user();
        $product = $this->product(90000, 0);

        $this->createQualifiedSignals($user, $product);

        $assignment = StoreCouponAssignment::query()->with('coupon')->firstOrFail();

        $this->assertSame(5000, $assignment->coupon->value);
        $this->assertGreaterThanOrEqual(
            (int) ceil($product->price_rials * 0.05),
            (int) $product->price_rials - (int) $product->cost_price_rials - (int) $assignment->coupon->value
        );
    }

    public function test_engine_does_not_offer_when_cost_is_unknown_or_margin_cannot_be_preserved(): void
    {
        $user = $this->user();
        $unknownCostProduct = $this->product(null, 0);
        $noMarginProduct = $this->product(100000, 0);

        $this->createQualifiedSignals($user, $unknownCostProduct);
        $this->assertDatabaseCount('store_coupon_assignments', 0);

        $secondUser = $this->user();
        $this->createQualifiedSignals($secondUser, $noMarginProduct);

        $this->assertDatabaseCount('store_coupon_assignments', 0);
    }

    public function test_already_purchased_product_is_suppressed(): void
    {
        $user = $this->user();
        $product = $this->product();
        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
            'status' => 'paid',
            'paid_at' => now(),
            'pricing_snapshot' => ['kind' => 'store'],
        ]);
        StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'quantity' => 1,
            'unit_price_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
        ]);

        $this->createQualifiedSignals($user, $product);

        $this->assertDatabaseCount('store_coupon_assignments', 0);
    }

    public function test_assigned_coupon_is_restricted_to_its_user_and_product(): void
    {
        $owner = $this->user();
        $product = $this->product();
        $this->createQualifiedSignals($owner, $product);
        $assignment = StoreCouponAssignment::with('coupon')->firstOrFail();

        $otherUser = $this->user(false);
        $this->actingAs($otherUser)->postJson(route('cart.add', $product))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => $assignment->coupon->code])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $otherProduct = $this->product(60000, 10000);
        $thirdUser = $this->user(false);
        $this->actingAs($thirdUser)->postJson(route('cart.add', $otherProduct))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => $assignment->coupon->code])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->actingAs($owner)->postJson(route('cart.add', $product))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => $assignment->coupon->code])
            ->assertOk()
            ->assertJsonPath('discount_rials', $assignment->coupon->value)
            ->assertJsonPath('coupon_assignment_id', $assignment->id);
    }

    public function test_successful_redemption_marks_the_assignment_and_consumes_the_single_use_coupon(): void
    {
        $user = $this->user();
        $product = $this->product();
        $this->createQualifiedSignals($user, $product);
        $assignment = StoreCouponAssignment::with('coupon')->firstOrFail();

        $order = Order::create([
            'user_id' => $user->id,
            'subtotal_rials' => 100000,
            'discount_rials' => $assignment->coupon->value,
            'tax_rials' => 0,
            'total_rials' => 100000 - $assignment->coupon->value,
            'status' => 'pending',
            'pricing_snapshot' => [
                'kind' => 'store',
                'coupon_code' => $assignment->coupon->code,
                'coupon_assignment_id' => $assignment->id,
                'coupon_product_id' => $product->id,
                'coupon_redemption_pending' => true,
            ],
        ]);
        StoreOrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'quantity' => 1,
            'unit_price_rials' => 100000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 100000,
        ]);

        app(StoreCouponRedemptionService::class)->redeemForPaidOrder($order);

        $this->assertSame('redeemed', $assignment->fresh()->status);
        $this->assertSame($order->id, $assignment->fresh()->redeemed_order_id);
        $this->assertNotNull($assignment->fresh()->redeemed_at);
        $this->assertSame(1, $assignment->coupon->fresh()->used_count);
    }

    public function test_abandoned_cart_is_detected_after_two_hours_and_only_once(): void
    {
        $user = $this->user();
        $product = $this->product();

        $this->actingAs($user)->postJson(route('cart.add', $product))->assertOk();
        $this->travel(3)->hours();

        $service = app(StoreCartAbandonmentService::class);
        $this->assertSame(1, $service->process());
        $this->assertDatabaseHas('farast_workflow_events', [
            'user_id' => $user->id,
            'event_type' => 'marketing.cart_abandoned',
        ]);
        $this->assertDatabaseCount('store_coupon_assignments', 1);
        $this->assertSame(0, $service->process());
    }


    public function test_offer_cooldown_is_scoped_to_the_product_not_the_whole_customer(): void
    {
        $user = $this->user();
        $firstProduct = $this->product();
        $secondProduct = $this->product();

        $this->createQualifiedSignals($user, $firstProduct);
        $this->assertDatabaseCount('store_coupon_assignments', 1);

        $this->createQualifiedSignals($user, $secondProduct);

        $this->assertDatabaseCount('store_coupon_assignments', 2);
        $this->assertDatabaseHas('store_coupon_assignments', [
            'user_id' => $user->id,
            'product_id' => $secondProduct->id,
            'status' => 'offered',
        ]);
    }

    public function test_product_page_presents_and_measures_the_active_product_specific_offer(): void
    {
        $user = $this->user();
        $product = $this->product();

        $this->createQualifiedSignals($user, $product);
        $assignment = StoreCouponAssignment::with('coupon')->firstOrFail();

        $this->actingAs($user)
            ->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee('پیشنهاد اختصاصی برای شما')
            ->assertSee($assignment->coupon->code)
            ->assertSee('data-copy-smart-coupon', false);

        $this->assertDatabaseHas('farast_workflow_events', [
            'user_id' => $user->id,
            'event_type' => 'marketing.offer_viewed',
        ]);

        $this->postJson(route('store.smart-offer.interaction', $assignment))
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertDatabaseHas('farast_workflow_events', [
            'user_id' => $user->id,
            'event_type' => 'marketing.offer_clicked',
        ]);
    }

    public function test_opt_out_revokes_outstanding_smart_offers_and_deactivates_their_codes(): void
    {
        $user = $this->user();
        $product = $this->product();
        $this->createQualifiedSignals($user, $product);
        $assignment = StoreCouponAssignment::with('coupon')->firstOrFail();

        $this->actingAs($user)->put(route('account.marketing-preferences.update'), [
            'marketing_consent' => 0,
        ])->assertRedirect(route('account.marketing-preferences.edit'));

        $this->assertSame('revoked', $assignment->fresh()->status);
        $this->assertFalse((bool) $assignment->coupon->fresh()->is_active);
    }

}
