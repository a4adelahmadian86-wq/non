<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardShellIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_platform_control_modules_render_inside_the_same_admin_dashboard_shell(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk()
            ->assertSee('id="platform-control"', false)
            ->assertSee('class="farast-admin-main"', false)
            ->assertSee('class="hero-quick-stats"', false)
            ->assertSee('به‌روزرسانی در')
            ->assertSee('id="activity-trends"', false)
            ->assertSee('روند فعالیت در ۳۰ روز اخیر')
            ->assertSee('dashboard-trend-chart', false)
            ->assertSee('dashboard-trend-sr-only', false)
            ->assertSee('صف پردازش')
            ->assertSee('ذخیره‌سازی و بکاپ')
            ->assertSee(route('admin.storage.index'))
            ->assertSee('نمای تکمیلی عملیات')
            ->assertSee('کل پروژه‌های ثبت‌شده')
            ->assertSee('درخواست صوتی ثبت‌شده')
            ->assertDontSee('اعتبارنامه Gemini')
            ->assertSee('پیام‌های خوانده‌نشده')
            ->assertSee(route('messenger.index'))
            ->assertSee('کاتالوگ محصولات')
            ->assertSee(route('admin.store.index'))
            ->assertDontSee('hero-orbit')
            ->assertSee('تیکت‌های نیازمند پیگیری')
            ->assertSee('پاسخ جدید مشتری')
            ->assertSee(route('admin.support.management'))
            ->assertSee('درآمد ماه جاری')
            ->assertSee(route('admin.finance'));

        $html = $response->getContent();
        $mainStart = strpos($html, '<main class="farast-admin-main">');
        $platformControl = strpos($html, 'id="platform-control"');
        $mainEnd = $platformControl === false ? false : strpos($html, '</main>', $platformControl);

        $this->assertNotFalse($mainStart);
        $this->assertNotFalse($platformControl);
        $this->assertNotFalse($mainEnd);
        $this->assertLessThan($platformControl, $mainStart);
        $this->assertLessThan($mainEnd, $platformControl);
        $this->assertSame(1, substr_count($html, 'class="farast-admin farast-admin-workspace"'));
        $this->assertSame(1, substr_count($html, 'class="farast-admin-main"'));
        $this->assertSame(1, substr_count($html, 'class="farast-admin-nav"'));
        $this->assertSame(1, substr_count($html, 'id="platform-control"'));
    }

    public function test_dashboard_crm_card_reports_overdue_and_due_today_followups_in_scope(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();

        DB::table('crm_follow_up_tasks')->insert([
            [
                'customer_id' => $customer->id,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'title' => 'پیگیری معوق',
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'customer_id' => $customer->id,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'title' => 'پیگیری امروز',
                'status' => 'open',
                'priority' => 'normal',
                'due_at' => today()->endOfDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'customer_id' => $customer->id,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'title' => 'پیگیری تکمیل‌شده',
                'status' => 'completed',
                'priority' => 'normal',
                'due_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('پیگیری‌های معوق CRM')
            ->assertSee('1 موعد امروز · 2 باز')
            ->assertSee(route('admin.crm.followups.index'));
    }

    public function test_dashboard_crm_summary_respects_organization_scope(): void
    {
        $organizationA = Organization::create([
            'name' => 'Dashboard Scope A',
            'slug' => 'dashboard-scope-a',
            'status' => 'active',
        ]);
        $organizationB = Organization::create([
            'name' => 'Dashboard Scope B',
            'slug' => 'dashboard-scope-b',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => $organizationA->id,
        ]);
        $inside = User::factory()->create(['organization_id' => $organizationA->id]);
        $outside = User::factory()->create(['organization_id' => $organizationB->id]);

        DB::table('crm_follow_up_tasks')->insert([
            [
                'customer_id' => $inside->id,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'title' => 'داخل دامنه',
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'customer_id' => $outside->id,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'title' => 'خارج دامنه',
                'status' => 'open',
                'priority' => 'high',
                'due_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('0 موعد امروز · 1 باز')
            ->assertSee('پیگیری‌های معوق CRM');
    }

    public function test_dashboard_support_card_breaks_down_customer_replies_and_open_tickets(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();

        DB::table('tickets')->insert([
            [
                'user_id' => $customer->id,
                'subject' => 'Customer replied',
                'status' => 'user_replied',
                'priority' => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $customer->id,
                'subject' => 'New ticket',
                'status' => 'open',
                'priority' => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $customer->id,
                'subject' => 'Waiting for customer',
                'status' => 'pending',
                'priority' => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('1 پاسخ جدید مشتری · 1 تیکت باز');
    }

    public function test_dashboard_product_summary_uses_real_catalog_counts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $categoryId = DB::table('store_categories')->insertGetId([
            'name' => 'Dashboard Category',
            'slug' => 'dashboard-category',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'category_id' => $categoryId,
            'title' => 'Dashboard Product',
            'slug' => 'dashboard-product',
            'sku' => 'DASHBOARD-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('کاتالوگ محصولات')
            ->assertSee('1 منتشرشده');
    }

    public function test_dashboard_audit_feed_never_exposes_events_outside_the_actor_scope(): void
    {
        $organizationA = Organization::create([
            'name' => 'Dashboard Audit Scope A',
            'slug' => 'dashboard-audit-scope-a',
            'status' => 'active',
        ]);
        $organizationB = Organization::create([
            'name' => 'Dashboard Audit Scope B',
            'slug' => 'dashboard-audit-scope-b',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => $organizationA->id,
        ]);
        $inside = User::factory()->create(['organization_id' => $organizationA->id]);
        $outside = User::factory()->create(['organization_id' => $organizationB->id]);

        DB::table('audit_logs')->insert([
            [
                'user_id' => $inside->id,
                'action' => 'dashboard.scope_inside',
                'ip' => '127.0.0.1',
                'meta' => json_encode(['probe' => 'inside']),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'user_id' => $outside->id,
                'action' => 'dashboard.scope_outside',
                'ip' => '127.0.0.2',
                'meta' => json_encode(['probe' => 'outside']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        config([
            'farast_admin.scope_override_key' => 'dashboard_scope_override',
            'farast_admin.sections.audit.min_scope' => 'organization',
        ]);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Dashboard Scope Inside')
            ->assertDontSee('Dashboard Scope Outside');
    }

    public function test_dashboard_product_summary_respects_seller_scope(): void
    {
        $organizationA = Organization::create([
            'name' => 'Dashboard Product Scope A',
            'slug' => 'dashboard-product-scope-a',
            'status' => 'active',
        ]);
        $organizationB = Organization::create([
            'name' => 'Dashboard Product Scope B',
            'slug' => 'dashboard-product-scope-b',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => $organizationA->id,
        ]);
        $insideSeller = User::factory()->create(['organization_id' => $organizationA->id]);
        $outsideSeller = User::factory()->create(['organization_id' => $organizationB->id]);
        $categoryId = DB::table('store_categories')->insertGetId([
            'name' => 'Scoped Dashboard Category',
            'slug' => 'scoped-dashboard-category',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['seller_id' => $insideSeller->id, 'slug' => 'inside-scope-product', 'sku' => 'SCOPE-IN-001'],
            ['seller_id' => $outsideSeller->id, 'slug' => 'outside-scope-product', 'sku' => 'SCOPE-OUT-001'],
        ] as $product) {
            DB::table('store_products')->insert([
                'category_id' => $categoryId,
                'seller_id' => $product['seller_id'],
                'title' => $product['slug'],
                'slug' => $product['slug'],
                'sku' => $product['sku'],
                'type' => 'digital_file',
                'price_rials' => 100000,
                'status' => 'published',
                'published_at' => now()->subMinute(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        config([
            'farast_admin.scope_override_key' => 'dashboard_scope_override',
            'farast_admin.sections.store.min_scope' => 'organization',
        ]);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('کاتالوگ محصولات')
            ->assertSee('1 منتشرشده · 0 پیش‌نویس');
    }

    public function test_dashboard_activity_feed_displays_real_audit_events_and_links_to_the_audit_ledger(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Activity Feed Admin',
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $admin->id,
            'action' => 'dashboard.integration_probe',
            'ip' => '127.0.0.1',
            'meta' => json_encode(['source' => 'feature-test']),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $response->assertOk()
            ->assertSee('فعالیت‌های اخیر')
            ->assertSee('Dashboard Integration Probe')
            ->assertSee('Activity Feed Admin')
            ->assertSee(route('admin.audit'));
    }
}
