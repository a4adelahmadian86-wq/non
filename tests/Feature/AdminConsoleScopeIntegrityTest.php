<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveAdminScope;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SiteSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
            ->assertDontSee('id="content"')
            ->assertDontSee('id="ai"')
            ->assertDontSee('id="platform-control"')
            ->assertDontSee('id="system-health"');

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
    public function test_dashboard_gemini_fallback_model_field_is_persisted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->from(route('admin.index'))
            ->post(route('admin.settings.update'), [
                'gemini_model' => 'gemini-3.8-flash',
                'gemini_fallback_model' => 'gemini-2.5-flash',
            ])
            ->assertRedirect(route('admin.index'));

        $this->assertSame('gemini-2.5-flash', SiteSetting::read('gemini_fallback_model'));
    }

    public function test_team_scope_does_not_include_global_admin_accounts_as_team_members(): void
    {
        $organization = Organization::create(['name' => 'Scoped Org', 'slug' => 'scoped-org']);
        $supervisor = User::factory()->create([
            'role' => User::ROLE_TEAM_SUPERVISOR,
            'organization_id' => $organization->id,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'organization_id' => $organization->id,
        ]);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Scoped Team',
            'slug' => 'scoped-team',
            'supervisor_id' => $supervisor->id,
        ]);

        DB::table('team_user')->insert([
            ['team_id' => $team->id, 'user_id' => $admin->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $team->id, 'user_id' => $employee->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $visibleIds = ResolveAdminScope::visibleUserIds($supervisor, ResolveAdminScope::SCOPE_TEAM);

        $this->assertContains($supervisor->id, $visibleIds);
        $this->assertContains($employee->id, $visibleIds);
        $this->assertNotContains($admin->id, $visibleIds);
    }

    public function test_role_permission_changes_are_validated_and_applied_as_one_transaction(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $documentsPermission = Permission::where('key', 'documents.view')->firstOrFail();
        $usersPermission = Permission::where('key', 'users.view')->firstOrFail();

        RolePermission::where('role', User::ROLE_EMPLOYEE)->delete();
        RolePermission::create([
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $documentsPermission->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.access'))
            ->post(route('admin.access.roles.update', User::ROLE_EMPLOYEE), [
                'permissions' => ['users.view'],
            ])
            ->assertRedirect(route('admin.access'));

        $this->assertDatabaseHas('role_permissions', [
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $usersPermission->id,
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'role' => User::ROLE_EMPLOYEE,
            'permission_id' => $documentsPermission->id,
        ]);
    }

    public function test_organization_manager_sees_only_their_organization_without_global_create_controls(): void
    {
        $organization = Organization::create(['name' => 'Manager Org', 'slug' => 'manager-org']);
        $manager = User::factory()->create([
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'organization_id' => $organization->id,
        ]);

        $this->actingAs($manager)
            ->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee('Manager Org')
            ->assertDontSee('admin.organizations.store')
            ->assertDontSee('ایجاد سازمان');
    }

    public function test_global_admin_dashboard_surfaces_internal_connection_health(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('id="system-health"')
            ->assertSee('پایگاه داده')
            ->assertSee('صف پردازش');
    }

}
