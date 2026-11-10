<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Team;
use App\Models\User;
use App\Services\AdminConsoleService;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvancedAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(AuthorizationService::class)->syncDefaults();
    }

    private function user(string $mobile, string $role = User::ROLE_EMPLOYEE, ?int $organizationId = null): User
    {
        return User::create([
            'name' => 'Access QA',
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => $role,
            'organization_id' => $organizationId,
            'is_blocked' => false,
        ]);
    }

    private function permissionId(string $key): int
    {
        return (int) Permission::query()->where('key', $key)->value('id');
    }

    private function role(string $key, string $scope = 'global', ?int $scopeId = null): int
    {
        return DB::table('farast_roles')->insertGetId([
            'key' => $key,
            'name' => $key,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grant(int $roleId, string $permission, string $scope = 'global', ?array $conditions = null): void
    {
        DB::table('farast_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $this->permissionId($permission),
            'scope_type' => $scope,
            'conditions' => $conditions ? json_encode($conditions) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assign(User $user, int $roleId, string $scope = 'global', ?int $scopeId = null): void
    {
        DB::table('farast_user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_custom_role_grants_permission_with_organization_boundary(): void
    {
        $organizationA = Organization::create(['name' => 'Access A', 'slug' => 'access-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Access B', 'slug' => 'access-b', 'status' => 'active']);
        $employee = $this->user('09140000101', User::ROLE_ORGANIZATION_MANAGER, $organizationA->id);
        $roleId = $this->role('custom-org-auditor', 'organization', $organizationA->id);

        $this->grant($roleId, 'access.audit', 'organization');
        $this->assign($employee, $roleId, 'organization', $organizationA->id);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($employee, 'access.audit'));
        $this->assertTrue($authorization->allowsFor($employee, 'access.audit', ['organization_id' => $organizationA->id]));
        $this->assertFalse($authorization->allowsFor($employee, 'access.audit', ['organization_id' => $organizationB->id]));
    }

    public function test_explicit_user_deny_overrides_a_role_grant(): void
    {
        $employee = $this->user('09140000102');
        $roleId = $this->role('custom-auditor');
        $this->grant($roleId, 'access.audit');
        $this->assign($employee, $roleId);

        DB::table('farast_user_permission_overrides')->insert([
            'user_id' => $employee->id,
            'permission_id' => $this->permissionId('access.audit'),
            'effect' => 'deny',
            'scope_type' => 'global',
            'reason' => 'Separation of duties test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(AuthorizationService::class)->allows($employee, 'access.audit'));
    }

    public function test_direct_allow_and_expired_override_are_evaluated_safely(): void
    {
        $employee = $this->user('09140000103');
        $permissionId = $this->permissionId('reports.workflows');

        DB::table('farast_user_permission_overrides')->insert([
            'user_id' => $employee->id,
            'permission_id' => $permissionId,
            'effect' => 'allow',
            'scope_type' => 'own',
            'reason' => 'Temporary reporting access',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($employee, 'reports.workflows'));

        DB::table('farast_user_permission_overrides')->where('user_id', $employee->id)->update([
            'expires_at' => now()->subMinute(),
        ]);
        $authorization->forgetUser($employee->id);

        $this->assertFalse($authorization->allows($employee, 'reports.workflows'));
    }

    public function test_role_permission_conditions_use_only_the_declared_safe_operators(): void
    {
        $employee = $this->user('09140000104');
        $roleId = $this->role('custom-conditional-operator');
        $this->grant($roleId, 'access.audit', 'global', [
            ['field' => 'order_kind', 'operator' => 'equals', 'value' => 'typing'],
            ['field' => 'amount_rials', 'operator' => 'greater_or_equal', 'value' => 1000],
        ]);
        $this->assign($employee, $roleId);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allowsFor($employee, 'access.audit', [
            'owner_id' => $employee->id,
            'order_kind' => 'typing',
            'amount_rials' => 1500,
        ]));
        $this->assertFalse($authorization->allowsFor($employee, 'access.audit', [
            'owner_id' => $employee->id,
            'order_kind' => 'store',
            'amount_rials' => 1500,
        ]));
        $this->assertFalse($authorization->allowsFor($employee, 'access.audit', [
            'owner_id' => $employee->id,
            'order_kind' => 'typing',
            'amount_rials' => 900,
        ]));
    }

    public function test_organization_and_team_scopes_fail_closed_when_resource_scope_is_null(): void
    {
        $organization = Organization::create(['name' => 'Null Scope Org', 'slug' => 'null-scope-org', 'status' => 'active']);
        $organizationManager = $this->user('09140000114', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $organizationRole = $this->role('null-org-scope-role', 'organization', $organization->id);
        $this->grant($organizationRole, 'access.audit', 'organization');
        $this->assign($organizationManager, $organizationRole, 'organization', $organization->id);

        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->allowsFor($organizationManager, 'access.audit', [
            'organization_id' => null,
            'owner_id' => null,
            'team_id' => null,
        ]));
        $this->assertTrue($authorization->allowsFor($organizationManager, 'access.audit', [
            'organization_id' => $organization->id,
            'owner_id' => null,
            'team_id' => null,
        ]));

        $teamSupervisor = $this->user('09140000115', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Null Scope Team',
            'slug' => 'null-scope-team',
            'supervisor_id' => $teamSupervisor->id,
        ]);
        $teamRole = $this->role('null-team-scope-role', 'team', $team->id);
        $this->grant($teamRole, 'access.audit', 'team');
        $this->assign($teamSupervisor, $teamRole, 'team', $team->id);

        $this->assertFalse($authorization->allowsFor($teamSupervisor, 'access.audit', [
            'organization_id' => $organization->id,
            'team_id' => null,
            'owner_id' => null,
        ]));
        $this->assertTrue($authorization->allowsFor($teamSupervisor, 'access.audit', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_id' => null,
        ]));
    }

    public function test_record_level_legacy_grants_fail_closed_without_an_owner_attribute(): void
    {
        $employee = $this->user('09140000109');
        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->allows($employee, 'documents.view'));
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', ['status' => 'active']));
        $this->assertTrue($authorization->allowsFor($employee, 'documents.view', ['owner_id' => $employee->id, 'status' => 'active']));
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', ['owner_id' => $employee->id + 1, 'status' => 'active']));
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', ['owner_id' => null, 'user_id' => $employee->id, 'status' => 'active']));
    }

    public function test_an_admin_request_scope_cannot_leak_into_another_users_permission_decision(): void
    {
        $admin = $this->user('09140000112', User::ROLE_ADMIN);
        $employee = $this->user('09140000113');

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->assertFalse(app(AuthorizationService::class)->allowsFor(
            $employee,
            'documents.view',
            ['owner_id' => $employee->id + 1]
        ));
    }

    public function test_blocked_users_never_receive_dynamic_permissions_and_admin_remains_break_glass(): void
    {
        $employee = $this->user('09140000105');
        $roleId = $this->role('custom-access-auditor');
        $this->grant($roleId, 'access.audit');
        $this->assign($employee, $roleId);

        $employee->forceFill(['is_blocked' => true])->save();
        $this->assertFalse(app(AuthorizationService::class)->allows($employee, 'access.audit'));

        $admin = $this->user('09140000106', User::ROLE_ADMIN);
        $this->assertTrue(app(AuthorizationService::class)->allows($admin, 'automation.manage'));
    }

    public function test_admin_can_create_assign_and_revoke_scoped_custom_access(): void
    {
        $organization = Organization::create(['name' => 'Managed Access Org', 'slug' => 'managed-access-org', 'status' => 'active']);
        $admin = $this->user('09140000110', User::ROLE_ADMIN);
        $manager = $this->user('09140000111', User::ROLE_ORGANIZATION_MANAGER, $organization->id);

        $this->actingAs($admin)->post('/admin/access-center/roles', [
            'name' => 'بازرس سازمان',
            'description' => 'مشاهده ممیزی در محدوده سازمان',
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
        ])->assertRedirect();

        $role = DB::table('farast_roles')->where('name', 'بازرس سازمان')->first();
        $this->assertNotNull($role);

        $this->post('/admin/access-center/roles/'.$role->id.'/permissions', [
            'scope_type' => 'organization',
            'permissions' => ['access.audit'],
        ])->assertRedirect();

        $this->post('/admin/access-center/users/'.$manager->id.'/roles', [
            'role_id' => $role->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('farast_user_roles', [
            'user_id' => $manager->id,
            'role_id' => $role->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
        ]);
        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allowsFor($manager, 'access.audit', ['organization_id' => $organization->id]));

        $this->post('/admin/access-center/users/'.$manager->id.'/overrides', [
            'permission' => 'access.audit',
            'effect' => 'deny',
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'reason' => 'Temporary separation of duties',
        ])->assertRedirect();

        $this->assertFalse(app(AuthorizationService::class)->allowsFor($manager, 'access.audit', ['organization_id' => $organization->id]));
        $this->assertDatabaseHas('farast_access_audit_logs', ['action' => 'role.created', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('farast_access_audit_logs', ['action' => 'user.role.assigned', 'subject_user_id' => $manager->id]);
        $this->assertDatabaseHas('farast_access_audit_logs', ['action' => 'user.permission.override.created', 'subject_user_id' => $manager->id]);
    }

    public function test_console_user_lists_apply_custom_team_scope_to_each_record(): void
    {
        $organization = Organization::create(['name' => 'Console Scope Org', 'slug' => 'console-scope-org', 'status' => 'active']);
        $manager = $this->user('09140000121', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $otherSupervisor = $this->user('09140000122', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $teamA = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Visible Team',
            'slug' => 'visible-team',
            'supervisor_id' => $manager->id,
        ]);
        $teamB = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Hidden Team',
            'slug' => 'hidden-team',
            'supervisor_id' => $otherSupervisor->id,
        ]);
        $memberA = $this->user('09140000123', User::ROLE_EMPLOYEE, $organization->id);
        $memberB = $this->user('09140000124', User::ROLE_EMPLOYEE, $organization->id);
        $unassigned = $this->user('09140000125', User::ROLE_EMPLOYEE, $organization->id);

        DB::table('team_user')->insert([
            ['team_id' => $teamA->id, 'user_id' => $memberA->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $teamB->id, 'user_id' => $memberB->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $roleId = $this->role('console-team-users-view', 'team', $teamA->id);
        $this->grant($roleId, 'users.view', 'team');
        $this->assign($manager, $roleId, 'team', $teamA->id);

        $this->actingAs($manager);
        request()->attributes->set('farast.scope', 'organization');
        request()->attributes->set('farast.section_permission', 'users.view');

        $visibleIds = app(AdminConsoleService::class)->visibleUserIds($manager);

        $this->assertContains($manager->id, $visibleIds);
        $this->assertContains($memberA->id, $visibleIds);
        $this->assertNotContains($memberB->id, $visibleIds);
        $this->assertNotContains($unassigned->id, $visibleIds);
    }

    public function test_organization_role_cannot_be_assigned_outside_its_parent_organization(): void
    {
        $organizationA = Organization::create(['name' => 'Role Parent A', 'slug' => 'role-parent-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Role Parent B', 'slug' => 'role-parent-b', 'status' => 'active']);
        $admin = $this->user('09140000116', User::ROLE_ADMIN);
        $target = $this->user('09140000117', User::ROLE_ORGANIZATION_MANAGER, $organizationB->id);
        $roleId = $this->role('organization-parent-bound-role', 'organization', $organizationA->id);

        $this->actingAs($admin)->post('/admin/access-center/users/'.$target->id.'/roles', [
            'role_id' => $roleId,
            'scope_type' => 'organization',
            'scope_id' => $organizationB->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('farast_user_roles', [
            'user_id' => $target->id,
            'role_id' => $roleId,
        ]);
        $this->assertDatabaseMissing('farast_access_audit_logs', [
            'action' => 'user.role.assigned',
            'subject_user_id' => $target->id,
        ]);
    }

    public function test_team_scoped_role_cannot_be_assigned_to_a_different_team(): void
    {
        $organization = Organization::create(['name' => 'Team Scope Org', 'slug' => 'team-scope-org', 'status' => 'active']);
        $admin = $this->user('09140000118', User::ROLE_ADMIN);
        $supervisor = $this->user('09140000119', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $teamA = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Role Team A',
            'slug' => 'role-team-a',
            'supervisor_id' => $supervisor->id,
        ]);
        $teamB = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Role Team B',
            'slug' => 'role-team-b',
            'supervisor_id' => $supervisor->id,
        ]);
        $roleId = $this->role('team-parent-bound-role', 'team', $teamA->id);

        $this->actingAs($admin)->post('/admin/access-center/users/'.$supervisor->id.'/roles', [
            'role_id' => $roleId,
            'scope_type' => 'team',
            'scope_id' => $teamB->id,
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('farast_user_roles', [
            'user_id' => $supervisor->id,
            'role_id' => $roleId,
        ]);
    }

    public function test_role_change_and_audit_log_are_atomic(): void
    {
        $admin = $this->user('09140000120', User::ROLE_ADMIN);
        DB::unprepared("CREATE TRIGGER fail_access_audit BEFORE INSERT ON farast_access_audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");

        $this->actingAs($admin)->post('/admin/access-center/roles', [
            'name' => 'نقش بدون ممیزی',
            'description' => 'نباید بدون ثبت ممیزی ذخیره شود',
            'scope_type' => 'global',
        ])->assertServerError();

        $this->assertDatabaseMissing('farast_roles', ['name' => 'نقش بدون ممیزی']);
    }

    public function test_advanced_access_center_is_only_available_to_the_platform_admin(): void
    {
        $admin = $this->user('09140000107', User::ROLE_ADMIN);
        $employee = $this->user('09140000108', User::ROLE_EMPLOYEE);

        $this->actingAs($admin)->get('/admin/access-center')->assertOk();
        $this->actingAs($employee)->get('/admin/access-center')->assertForbidden();
    }
}
