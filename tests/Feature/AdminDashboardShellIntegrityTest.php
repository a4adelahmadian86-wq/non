<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('class="farast-admin-main"', false);

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
}
