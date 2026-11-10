<?php

namespace Tests\Feature;

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
                'due_at' => now()->addHour(),
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
                'completed_at' => now(),
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
