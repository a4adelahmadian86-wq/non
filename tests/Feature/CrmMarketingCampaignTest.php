<?php

namespace Tests\Feature;

use App\Models\FarastNotification;
use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreCouponAssignment;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\CrmAudienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmMarketingCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function user(string $mobile, string $role = User::ROLE_ADMIN): User
    {
        return User::create([
            'name' => 'Marketing '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_segment_preview_and_campaign_send_create_in_app_notifications_once(): void
    {
        $admin = $this->user('09151110001');
        $customer = $this->user('09151110002', User::ROLE_EMPLOYEE);
        $customer->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $other = $this->user('09151110003', User::ROLE_EMPLOYEE);
        Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 100000, 'discount_rials' => 0, 'tax_rials' => 0,
            'total_rials' => 100000, 'status' => 'paid', 'pricing_snapshot' => ['kind' => 'test'],
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'خریداران', 'filters' => ['paid_customer' => true],
        ])->assertCreated()->assertJsonPath('audience_count', 1);

        $segmentId = DB::table('crm_customer_segments')->value('id');
        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'اطلاع‌رسانی خرید', 'segment_id' => $segmentId,
            'title' => 'خبر جدید', 'body' => 'یک پیام آزمایشی',
        ])->assertCreated()->assertJsonPath('audience_count', 1);

        $campaignId = DB::table('crm_marketing_campaigns')->value('id');
        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')
            ->assertOk()->assertJsonPath('recipient_count', 1);

        $this->assertDatabaseHas('crm_marketing_campaigns', ['id' => $campaignId, 'status' => 'sent']);
        $this->assertDatabaseHas('crm_campaign_recipients', ['campaign_id' => $campaignId, 'user_id' => $customer->id]);
        $this->assertDatabaseMissing('crm_campaign_recipients', ['campaign_id' => $campaignId, 'user_id' => $other->id]);
        $this->assertDatabaseHas('farast_notifications', ['user_id' => $customer->id, 'type' => 'announcement_campaign']);

        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')->assertStatus(409);
        $this->assertSame(1, FarastNotification::where('type', 'announcement_campaign')->count());
    }

    public function test_marketing_segments_and_campaigns_are_scoped_to_their_creator_domain(): void
    {
        $orgA = \App\Models\Organization::create(['name' => 'Campaign Scope A', 'slug' => 'campaign-scope-a', 'status' => 'active']);
        $orgB = \App\Models\Organization::create(['name' => 'Campaign Scope B', 'slug' => 'campaign-scope-b', 'status' => 'active']);

        $adminA = $this->user('09151110007');
        $adminA->organization_id = $orgA->id;
        $adminA->save();
        $adminB = $this->user('09151110008');
        $adminB->organization_id = $orgB->id;
        $adminB->save();

        $customerA = $this->user('09151110009', User::ROLE_EMPLOYEE);
        $customerA->organization_id = $orgA->id;
        $customerA->save();
        $customerB = $this->user('09151110010', User::ROLE_EMPLOYEE);
        $customerB->organization_id = $orgB->id;
        $customerB->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($adminA)->postJson('/admin/crm/marketing/segments', [
            'name' => 'سگمنت سازمان الف',
            'filters' => [],
        ])->assertCreated();

        $segmentId = DB::table('crm_customer_segments')->where('created_by', $adminA->id)->value('id');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'کمپین سازمان الف',
            'segment_id' => $segmentId,
            'title' => 'پیام سازمان الف',
            'body' => 'این پیام فقط برای سازمان الف است.',
        ])->assertCreated();

        $campaignId = DB::table('crm_marketing_campaigns')->where('created_by', $adminA->id)->value('id');

        $this->actingAs($adminB)
            ->getJson('/admin/crm/marketing/segments')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->getJson('/admin/crm/marketing/campaigns')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'استفاده غیرمجاز از سگمنت',
            'segment_id' => $segmentId,
            'title' => 'پیام',
            'body' => 'نباید ساخته شود.',
        ])->assertNotFound();

        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')
            ->assertNotFound();

        $this->assertDatabaseMissing('crm_campaign_recipients', [
            'campaign_id' => $campaignId,
            'user_id' => $customerB->id,
        ]);
        $this->assertDatabaseMissing('farast_notifications', [
            'user_id' => $customerB->id,
            'type' => 'announcement_campaign',
        ]);
    }

    public function test_campaign_recipient_audience_respects_organization_scope(): void
    {
        $orgA = \App\Models\Organization::create(['name' => 'Marketing A', 'slug' => 'marketing-a', 'status' => 'active']);
        $orgB = \App\Models\Organization::create(['name' => 'Marketing B', 'slug' => 'marketing-b', 'status' => 'active']);
        $admin = $this->user('09151110004');
        $admin->organization_id = $orgA->id;
        $admin->save();
        $inside = $this->user('09151110005', User::ROLE_EMPLOYEE);
        $inside->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $outside = $this->user('09151110006', User::ROLE_EMPLOYEE);
        $inside->organization_id = $orgA->id;
        $inside->save();
        $outside->organization_id = $orgB->id;
        $outside->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'همه مشتریان', 'filters' => [],
        ])->assertCreated()->assertJsonPath('audience_count', 1);
    }

    public function test_campaign_issues_product_specific_offers_with_margin_and_budget_caps_only_to_consented_customers(): void
    {
        $admin = $this->user('09151110021');
        $first = $this->user('09151110022', User::ROLE_EMPLOYEE);
        $first->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $second = $this->user('09151110023', User::ROLE_EMPLOYEE);
        $second->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $optedOut = $this->user('09151110024', User::ROLE_EMPLOYEE);

        $category = StoreCategory::create([
            'name' => 'دسته مشوق کمپین',
            'slug' => 'campaign-incentive-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول مشوق کمپین',
            'slug' => 'campaign-incentive-product',
            'sku' => 'CAMPAIGN-INCENTIVE-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'مخاطبان دارای رضایت',
            'filters' => [],
        ])->assertCreated()->assertJsonPath('audience_count', 2);
        $segmentId = DB::table('crm_customer_segments')->where('created_by', $admin->id)->value('id');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'کمپین مشوق محصول',
            'segment_id' => $segmentId,
            'title' => 'پیشنهاد ویژه',
            'body' => 'برای شما یک پیشنهاد ویژه داریم.',
            'incentive_product_id' => $product->id,
            'incentive_discount_percent' => 10,
            'incentive_valid_hours' => 48,
            'budget_cap_rials' => 10000,
        ])->assertCreated()->assertJsonPath('audience_count', 2);

        $campaignId = DB::table('crm_marketing_campaigns')->where('name', 'کمپین مشوق محصول')->value('id');
        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')
            ->assertOk()
            ->assertJsonPath('recipient_count', 2);

        $assignments = StoreCouponAssignment::query()->where('campaign_id', $campaignId)->with('coupon')->get();
        $this->assertCount(1, $assignments, 'The campaign budget must reserve no more than one 10,000 rial offer.');
        $this->assertSame(10000, (int) $assignments->first()->coupon->value);
        $this->assertContains((int) $assignments->first()->user_id, [$first->id, $second->id]);
        $this->assertDatabaseMissing('crm_campaign_recipients', [
            'campaign_id' => $campaignId,
            'user_id' => $optedOut->id,
        ]);
        $this->assertSame(2, DB::table('crm_campaign_recipients')->where('campaign_id', $campaignId)->count());

        $offer = $assignments->first();
        $offerUser = User::query()->findOrFail($offer->user_id);
        $this->actingAs($offerUser)
            ->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee($offer->coupon->code);
        $this->postJson(route('store.smart-offer.interaction', $offer))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $campaign = $this->actingAs($admin)->getJson('/admin/crm/marketing/campaigns')->assertOk();
        $campaign->assertJsonPath('data.data.0.offer_count', 1)
            ->assertJsonPath('data.data.0.offer_view_count', 1)
            ->assertJsonPath('data.data.0.offer_click_count', 1)
            ->assertJsonPath('data.data.0.view_to_click_rate', 100)
            ->assertJsonPath('data.data.0.reserved_discount_rials', 10000)
            ->assertJsonPath('data.data.0.budget_remaining_rials', 0);
        $this->assertSame(10000, (int) DB::table('crm_marketing_campaigns')->where('id', $campaignId)->value('budget_cap_rials'));
    }



    public function test_behavioral_segments_match_recent_product_signals_and_never_include_opted_out_users(): void
    {
        $admin = $this->user('09151110031');
        $interested = $this->user('09151110032', User::ROLE_EMPLOYEE);
        $interested->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $noSignal = $this->user('09151110033', User::ROLE_EMPLOYEE);
        $noSignal->forceFill(['marketing_consent' => true, 'marketing_consent_updated_at' => now()])->save();
        $optedOut = $this->user('09151110034', User::ROLE_EMPLOYEE);

        $category = StoreCategory::create([
            'name' => 'دسته سگمنت رفتاری',
            'slug' => 'behavior-segment-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول سگمنت رفتاری',
            'slug' => 'behavior-segment-product',
            'sku' => 'BEHAVIOR-SEGMENT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        DB::table('farast_workflow_events')->insert([
            'event_type' => 'marketing.cart_abandoned',
            'idempotency_key' => 'marketing:cart_abandoned:'.$interested->id.':'.$product->id.':behavior-test',
            'user_id' => $interested->id,
            'context' => json_encode(['product_id' => $product->id]),
            'matched_workflows' => 0,
            'processed_at' => null,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);
        DB::table('farast_workflow_events')->insert([
            'event_type' => 'marketing.cart_abandoned',
            'idempotency_key' => 'marketing:cart_abandoned:'.$optedOut->id.':'.$product->id.':behavior-test',
            'user_id' => $optedOut->id,
            'context' => json_encode(['product_id' => $product->id]),
            'matched_workflows' => 0,
            'processed_at' => null,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'رهاکنندگان سبد محصول هدف',
            'filters' => [
                'signal_event' => 'cart_abandoned',
                'signal_product_id' => $product->id,
                'signal_window_days' => 14,
            ],
        ])->assertCreated()->assertJsonPath('audience_count', 1);

        $segment = DB::table('crm_customer_segments')->where('created_by', $admin->id)->first();
        $matchedIds = app(CrmAudienceService::class)
            ->query(json_decode($segment->filters, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertSame([$interested->id], $matchedIds);
        $this->assertNotContains($noSignal->id, $matchedIds);
    }



    public function test_campaign_workspace_is_available_to_marketing_admins(): void
    {
        $admin = $this->user('09151110041');

        $this->actingAs($admin)
            ->get(route('admin.crm.marketing.index'))
            ->assertOk()
            ->assertSee('مرکز کمپین‌های بازاریابی')
            ->assertSee('ساخت سگمنت رفتاری')
            ->assertSee('سقف بودجه کمپین')
            ->assertSee('عملکرد و چرخه کمپین‌ها')
            ->assertSee('نقش مشتری')
            ->assertSee('وضعیت احراز هویت')
            ->assertSee('ثبت‌نام از')
            ->assertSee('حداقل تعداد سفارش')
            ->assertSee('حداقل یک سفارش پرداخت‌شده')
            ->assertSee('name="paid_customer"', false);
    }



    public function test_scoped_marketing_users_do_not_receive_product_cost_or_manage_margin_based_offers(): void
    {
        $organization = \\App\\Models\\Organization::create([
            'name' => 'Scoped Marketing Organization',
            'slug' => 'scoped-marketing-organization',
            'status' => 'active',
        ]);
        $manager = $this->user('09151110041', User::ROLE_ORGANIZATION_MANAGER);
        $manager->organization_id = $organization->id;
        $manager->save();

        $category = StoreCategory::create([
            'name' => 'دسته محرمانه قیمت تمام‌شده',
            'slug' => 'scoped-marketing-cost-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول دارای بهای تمام‌شده',
            'slug' => 'scoped-marketing-cost-product',
            'sku' => 'SCOPED-MARKETING-COST-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($manager)->get('/admin/crm/marketing')->assertOk();
        $listedProduct = $response->viewData('products')->firstWhere('id', $product->id);
        $this->assertNotNull($listedProduct);
        $this->assertArrayNotHasKey('cost_price_rials', $listedProduct->getAttributes());

        $this->postJson('/admin/crm/marketing/segments', [
            'name' => 'مخاطبان سازمان',
            'filters' => [],
        ])->assertCreated();
        $segmentId = DB::table('crm_customer_segments')->where('created_by', $manager->id)->value('id');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'مشوق خارج از دامنه',
            'segment_id' => $segmentId,
            'title' => 'پیشنهاد',
            'body' => 'متن پیشنهاد',
            'incentive_product_id' => $product->id,
            'incentive_discount_percent' => 10,
            'budget_cap_rials' => 10000,
        ])->assertForbidden();
    }

    public function test_incentive_campaign_cannot_be_sent_after_scope_is_narrowed_from_global(): void
    {
        $organization = \\App\\Models\\Organization::create([
            'name' => 'Narrowed Marketing Organization',
            'slug' => 'narrowed-marketing-organization',
            'status' => 'active',
        ]);
        $admin = $this->user('09151110042');
        $admin->organization_id = $organization->id;
        $admin->save();

        $category = StoreCategory::create([
            'name' => 'دسته تغییر دامنه',
            'slug' => 'narrowed-marketing-cost-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول تغییر دامنه',
            'slug' => 'narrowed-marketing-cost-product',
            'sku' => 'NARROWED-MARKETING-COST-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'cost_price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/crm/marketing/segments', [
            'name' => 'سگمنت مدیر اصلی',
            'filters' => [],
        ])->assertCreated();
        $segmentId = DB::table('crm_customer_segments')->where('created_by', $admin->id)->value('id');

        $this->postJson('/admin/crm/marketing/campaigns', [
            'name' => 'کمپین مشوق سراسری',
            'segment_id' => $segmentId,
            'title' => 'پیشنهاد',
            'body' => 'متن پیشنهاد',
            'incentive_product_id' => $product->id,
            'incentive_discount_percent' => 10,
            'budget_cap_rials' => 10000,
        ])->assertCreated();
        $campaignId = DB::table('crm_marketing_campaigns')->where('created_by', $admin->id)->value('id');

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \\App\\Models\\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->postJson('/admin/crm/marketing/campaigns/'.$campaignId.'/send')->assertForbidden();
        $this->assertSame('draft', DB::table('crm_marketing_campaigns')->where('id', $campaignId)->value('status'));
    }


}
