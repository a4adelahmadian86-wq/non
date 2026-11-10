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
            ->assertSee('تیکت‌های نیازمند پیگیری')
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
