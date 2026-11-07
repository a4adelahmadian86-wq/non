<?php

namespace Tests\\Feature;

use App\\Models\\Permission;
use App\\Models\\User;
use App\\Services\\AuthorizationService;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class AdminConsoleScopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_dashboard_does_not_render_user_records_or_admin_controls_without_their_permissions(): void
    {
        $viewer = User::factory()->create([
            'name' => 'کاربر محدود',
            'email' => 'private-dashboard-user@example.test',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $response = $this->actingAs($viewer)->get(route('admin.index'));

        $response->assertOk()
            ->assertDontSee('private-dashboard-user@example.test')
            ->assertDontSee('id="users"')
            ->assertDontSee('name="gemini_api_key"')
            ->assertDontSee('name="voice_groq_key"')
            ->assertDontSee('id="voice-settings"')
            ->assertDontSee('id="content"');

        $this->actingAs($viewer)->get(route('admin.users'))->assertForbidden();
    }

    public function test_dashboard_keeps_scoped_aggregate_metrics_without_exposing_user_detail_lists(): void
    {
        $viewer = User::factory()->create([
            'name' => 'کاربر محدود',
            'email' => 'private-aggregate@example.test',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $response = $this->actingAs($viewer)->get(route('admin.index'));

        $response->assertOk()
            ->assertSee('کاربران')
            ->assertDontSee('private-aggregate@example.test');
    }
}
