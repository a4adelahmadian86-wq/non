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

    public function test_authorization_cache_is_lifecycle_scoped_and_explicitly_invalidated(): void
    {
        $authorization = app(AuthorizationService::class);
        $this->assertSame($authorization, app(AuthorizationService::class));

        $employee = $this->user('09140000136');
        $roleId = $this->role('cache-invalidation-role');
        $this->grant($roleId, 'access.audit');
        $this->assign($employee, $roleId);

        $this->assertTrue($authorization->allows($employee, 'access.audit'));

        DB::table('farast_user_roles')
            ->where('user_id', $employee->id)
            ->where('role_id', $roleId)
            ->delete();

        // The same request cache remains deterministic until the write path
        // explicitly invalidates it; it must never survive into another lifecycle.
        $this->assertTrue($authorization->allows($employee, 'access.audit'));
        $authorization->forgetUser($employee->id);
        $this->assertFalse($authorization->allows($employee, 'access.audit'));
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
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', []));
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

    public function test_resource_middleware_does_not_fall_back_from_null_owner_to_user_id(): void
    {
        $employee = $this->user('09140000130');
        $resource = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'documents';
            public $timestamps = false;
        };
        $resource->setRawAttributes([
            'id' => 9001,
            'owner_id' => null,
            'user_id' => $employee->id,
            'organization_id' => null,
            'team_id' => null,
            'status' => 'active',
        ]);

        $route = new \Illuminate\Routing\Route('GET', '/resource-check', fn () => null);
        $request = \Illuminate\Http\Request::create('/resource-check');
        $route->bind($request);
        $route->setParameter('resource', $resource);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $employee);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Middleware\EnsureResourcePermission::class)->handle(
            $request,
            fn () => response('unexpectedly allowed'),
            'documents.view'
        );
    }

    public function test_base_role_and_block_state_changes_are_recorded_in_access_audit(): void
    {
        $organization = Organization::create(['name' => 'Audit Role Org', 'slug' => 'audit-role-org', 'status' => 'active']);
        $admin = $this->user('09140000145', User::ROLE_ADMIN);
        $target = $this->user('09140000146', User::ROLE_EMPLOYEE, $organization->id);

        $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/role', [
            'role' => User::ROLE_ORGANIZATION_MANAGER,
        ])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => User::ROLE_ADMIN]);

        $this->post('/admin/users/'.$target->id.'/role', [
            'role' => User::ROLE_ORGANIZATION_MANAGER,
        ])->assertRedirect();

        $this->assertDatabaseHas('farast_access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $target->id,
            'action' => 'user.base_role.updated',
        ]);

        $this->post('/admin/users/'.$target->id.'/block')->assertRedirect();

        $this->assertDatabaseHas('farast_access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $target->id,
            'action' => 'user.blocked',
        ]);
    }

    public function test_capability_mutation_requires_its_own_permission_and_is_audited(): void
    {
        config(['farast_admin.multi_role_console' => true]);
        $organization = Organization::create(['name' => 'Capability Admin Org', 'slug' => 'capability-admin-org', 'status' => 'active']);
        $manager = $this->user('09140000147', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $target = $this->user('09140000148', User::ROLE_EMPLOYEE, $organization->id);
        $viewRole = $this->role('capability-customer-view', 'organization', $organization->id);
        $this->grant($viewRole, 'users.view', 'organization');
        $this->assign($manager, $viewRole, 'organization', $organization->id);
        $roleId = $this->role('capability-administrator', 'organization', $organization->id);
        $this->grant($roleId, 'users.capabilities.manage', 'organization');
        $this->assign($manager, $roleId, 'organization', $organization->id);

        $this->actingAs($manager)->post('/admin/users/'.$target->id.'/capabilities', [
            'can_ai' => true,
            'active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('user_capabilities', [
            'user_id' => $target->id,
            'can_ai' => true,
        ]);
        $this->assertDatabaseHas('farast_access_audit_logs', [
            'actor_id' => $manager->id,
            'subject_user_id' => $target->id,
            'action' => 'user.capabilities.updated',
        ]);

        $this->post('/admin/users/'.$manager->id.'/capabilities', [
            'can_ai' => true,
        ])->assertForbidden();
    }

    public function test_view_permission_cannot_be_used_as_a_record_write_permission(): void
    {
        $organization = Organization::create(['name' => 'Scoped Action Org', 'slug' => 'scoped-action-org', 'status' => 'active']);
        $manager = $this->user('09140000141', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $customer = $this->user('09140000142', User::ROLE_EMPLOYEE, $organization->id);

        // Isolate the scoped custom grant from the legacy organization-manager default.
        DB::table('role_permissions')
            ->where('role', User::ROLE_ORGANIZATION_MANAGER)
            ->where('permission_id', $this->permissionId('users.edit'))
            ->delete();
        app(AuthorizationService::class)->forgetUser();

        $viewRole = $this->role('organization-customer-view', 'organization', $organization->id);
        $this->grant($viewRole, 'users.view', 'organization');
        $this->assign($manager, $viewRole, 'organization', $organization->id);

        $selfEditRole = $this->role('self-only-user-edit', 'own');
        $this->grant($selfEditRole, 'users.edit', 'own');
        $this->assign($manager, $selfEditRole, 'own');

        $this->actingAs($manager);
        request()->attributes->set('farast.scope', 'organization');
        $console = app(\App\Services\AdminConsoleService::class);

        // Read access to a customer remains available inside the organization.
        $console->authorizeAction($manager, 'users', $customer->id);

        // The same manager can edit their own record, but not another customer:
        // the action permission is checked against the target record's scope.
        $console->authorizeAction($manager, 'users', $manager->id, 'users.edit');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $console->authorizeAction($manager, 'users', $customer->id, 'users.edit');
    }

    public function test_team_authority_and_role_assignment_cannot_cross_organization_boundaries(): void
    {
        $organizationA = Organization::create(['name' => 'Team Owner Org', 'slug' => 'team-owner-org', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Foreign Team Org', 'slug' => 'foreign-team-org', 'status' => 'active']);
        $manager = $this->user('09140000131', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $admin = $this->user('09140000132', User::ROLE_ADMIN);

        // Simulate inconsistent legacy data: both a manager pivot and supervisor
        // pointer connect an organization-A account to an organization-B team.
        $foreignTeam = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Foreign Managed Team',
            'slug' => 'foreign-managed-team',
            'supervisor_id' => $manager->id,
        ]);
        DB::table('team_user')->insert([
            'team_id' => $foreignTeam->id,
            'user_id' => $manager->id,
            'team_role' => 'manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = $this->role('foreign-team-role', 'team', $foreignTeam->id);
        $this->grant($roleId, 'users.view', 'team');
        $this->assign($manager, $roleId, 'team', $foreignTeam->id);

        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->allowsFor($manager, 'users.view', [
            'organization_id' => $organizationB->id,
            'team_id' => $foreignTeam->id,
        ]));
        $this->assertFalse($authorization->allowsFor($manager, 'users.view', [
            'organization_id' => $organizationA->id,
            'team_id' => $foreignTeam->id,
        ]));

        $this->assertSame(
            [$manager->id],
            \App\Http\Middleware\ResolveAdminScope::visibleUserIds($manager, 'team')
        );

        $this->actingAs($manager);
        request()->attributes->set('farast.scope', 'team');
        $this->assertSame([], app(AdminConsoleService::class)->visibleTeamIds($manager, 'users.view'));

        // Remove the deliberately injected corrupt assignment before testing that
        // the HTTP assignment endpoint rejects a second cross-organization grant.
        DB::table('farast_user_roles')->where('user_id', $manager->id)->where('role_id', $roleId)->delete();

        $this->actingAs($admin)->post('/admin/access-center/users/'.$manager->id.'/roles', [
            'role_id' => $roleId,
            'scope_type' => 'team',
            'scope_id' => $foreignTeam->id,
        ])->assertUnprocessable();

        $this->post('/admin/teams/'.$foreignTeam->id.'/members', [
            'user_id' => $manager->id,
            'team_role' => 'supervisor',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('farast_user_roles', [
            'user_id' => $manager->id,
            'role_id' => $roleId,
        ]);
    }

    public function test_team_manager_cannot_list_a_foreign_organization_user_joined_to_its_team(): void
    {
        $organizationA = Organization::create(['name' => 'Manager Home Org', 'slug' => 'manager-home-org', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Foreign Member Org', 'slug' => 'foreign-member-org', 'status' => 'active']);
        $manager = $this->user('09140000133', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $foreignMember = $this->user('09140000134', User::ROLE_EMPLOYEE, $organizationB->id);
        $team = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Home Organization Team',
            'slug' => 'home-organization-team',
            'supervisor_id' => $manager->id,
        ]);

        // Simulate a stale/corrupted cross-organization membership.
        DB::table('team_user')->insert([
            'team_id' => $team->id,
            'user_id' => $foreignMember->id,
            'team_role' => 'employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visibleIds = \App\Http\Middleware\ResolveAdminScope::visibleUserIds($manager, 'team');
        $this->assertNotContains($foreignMember->id, $visibleIds);

        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->allowsFor($manager, 'users.view', [
            'owner_id' => $foreignMember->id,
            'organization_id' => $organizationB->id,
            'team_ids' => [$team->id],
        ]));

        $this->actingAs($manager);
        request()->attributes->set('farast.scope', 'team');
        $this->assertNotContains(
            $foreignMember->id,
            app(AdminConsoleService::class)->visibleUserIds($manager, 'users.view')
        );
    }

    public function test_team_supervisor_is_not_treated_as_team_resource_owner(): void
    {
        $organizationA = Organization::create(['name' => 'Team Resource Org', 'slug' => 'team-resource-org', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Supervisor Account Org', 'slug' => 'supervisor-account-org', 'status' => 'active']);
        $employee = $this->user('09140000140', User::ROLE_EMPLOYEE, $organizationB->id);
        $team = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Foreign Supervisor Team',
            'slug' => 'foreign-supervisor-team',
            'supervisor_id' => $employee->id,
        ]);
        $roleId = $this->role('own-scope-team-resource', 'own');
        $this->grant($roleId, 'access.audit', 'own');
        $this->assign($employee, $roleId, 'own');

        $route = new \Illuminate\Routing\Route('GET', '/team-resource-check', fn () => null);
        $request = \Illuminate\Http\Request::create('/team-resource-check');
        $route->bind($request);
        $route->setParameter('resource', $team);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $employee);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Middleware\EnsureResourcePermission::class)->handle(
            $request,
            fn () => response('team supervisor is not the owner'),
            'access.audit'
        );
    }

    public function test_resource_creator_is_not_treated_as_the_current_owner(): void
    {
        $employee = $this->user('09140000135');
        $resource = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'documents';
            public $timestamps = false;
        };
        $resource->setRawAttributes([
            'id' => 9002,
            'created_by' => $employee->id,
            'organization_id' => null,
            'team_id' => null,
            'status' => 'active',
        ]);

        $route = new \Illuminate\Routing\Route('GET', '/resource-creator-check', fn () => null);
        $request = \Illuminate\Http\Request::create('/resource-creator-check');
        $route->bind($request);
        $route->setParameter('resource', $resource);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $employee);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Middleware\EnsureResourcePermission::class)->handle(
            $request,
            fn () => response('creator must not receive owner access'),
            'documents.view'
        );
    }

    public function test_team_membership_writes_are_atomic_audited_and_clear_primary_supervisor(): void
    {
        $organization = Organization::create(['name' => 'Team Audit Org', 'slug' => 'team-audit-org', 'status' => 'active']);
        $admin = $this->user('09140000143', User::ROLE_ADMIN);
        $supervisor = $this->user('09140000144', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $secondarySupervisor = $this->user('09140000149', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Audited Team',
            'slug' => 'audited-team',
        ]);

        $this->actingAs($admin)->post('/admin/teams/'.$team->id.'/members', [
            'user_id' => $supervisor->id,
            'team_role' => 'supervisor',
        ])->assertRedirect();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $supervisor->id,
            'team_role' => 'supervisor',
        ]);
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'supervisor_id' => $supervisor->id,
        ]);
        $this->assertDatabaseHas('farast_access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $supervisor->id,
            'action' => 'team.member.added',
        ]);

        $this->post('/admin/teams/'.$team->id.'/members', [
            'user_id' => $secondarySupervisor->id,
            'team_role' => 'supervisor',
        ])->assertRedirect();

        $this->delete('/admin/teams/'.$team->id.'/members/'.$supervisor->id)->assertRedirect();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $supervisor->id,
        ]);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $secondarySupervisor->id,
            'team_role' => 'supervisor',
        ]);
        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'supervisor_id' => $secondarySupervisor->id,
        ]);
        $this->assertDatabaseHas('farast_access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_user_id' => $supervisor->id,
            'action' => 'team.member.removed',
        ]);
    }

    public function test_existing_owner_and_supervisor_team_roles_grant_only_managed_team_scope(): void
    {
        $organization = Organization::create(['name' => 'Managed Roles Org', 'slug' => 'managed-roles-org', 'status' => 'active']);
        $manager = $this->user('09140000137', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $memberA = $this->user('09140000138', User::ROLE_EMPLOYEE, $organization->id);
        $memberB = $this->user('09140000139', User::ROLE_EMPLOYEE, $organization->id);
        $teamSupervisor = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Supervisor Pivot Team',
            'slug' => 'supervisor-pivot-team',
        ]);
        $teamOwner = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Owner Pivot Team',
            'slug' => 'owner-pivot-team',
        ]);

        DB::table('team_user')->insert([
            ['team_id' => $teamSupervisor->id, 'user_id' => $manager->id, 'team_role' => 'supervisor', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $teamSupervisor->id, 'user_id' => $memberA->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $teamOwner->id, 'user_id' => $manager->id, 'team_role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['team_id' => $teamOwner->id, 'user_id' => $memberB->id, 'team_role' => 'employee', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $supervisorRole = $this->role('supervisor-pivot-access', 'team', $teamSupervisor->id);
        $this->grant($supervisorRole, 'access.audit', 'team');
        $this->assign($manager, $supervisorRole, 'team', $teamSupervisor->id);
        $ownerRole = $this->role('owner-pivot-access', 'team', $teamOwner->id);
        $this->grant($ownerRole, 'access.audit', 'team');
        $this->assign($manager, $ownerRole, 'team', $teamOwner->id);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allowsFor($manager, 'access.audit', [
            'organization_id' => $organization->id,
            'team_id' => $teamSupervisor->id,
        ]));
        $this->assertTrue($authorization->allowsFor($manager, 'access.audit', [
            'organization_id' => $organization->id,
            'team_id' => $teamOwner->id,
        ]));

        $visibleUsers = \App\Http\Middleware\ResolveAdminScope::visibleUserIds($manager, 'team');
        $this->assertContains($memberA->id, $visibleUsers);
        $this->assertContains($memberB->id, $visibleUsers);

        $this->actingAs($manager);
        request()->attributes->set('farast.scope', 'team');
        $visibleTeams = app(AdminConsoleService::class)->visibleTeamIds($manager);
        $this->assertContains($teamSupervisor->id, $visibleTeams);
        $this->assertContains($teamOwner->id, $visibleTeams);
    }

    public function test_regular_team_membership_does_not_create_team_management_scope(): void
    {
        $organization = Organization::create(['name' => 'Member Scope Org', 'slug' => 'member-scope-org', 'status' => 'active']);
        $supervisor = $this->user('09140000126', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $actualSupervisor = $this->user('09140000127', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Ordinary Membership Team',
            'slug' => 'ordinary-membership-team',
            'supervisor_id' => $actualSupervisor->id,
        ]);
        DB::table('team_user')->insert([
            'team_id' => $team->id,
            'user_id' => $supervisor->id,
            'team_role' => 'employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = $this->role('ordinary-member-team-role', 'team', $team->id);
        $this->grant($roleId, 'access.audit', 'team');

        $admin = $this->user('09140000133', User::ROLE_ADMIN);
        $this->actingAs($admin)->post('/admin/access-center/users/'.$supervisor->id.'/roles', [
            'role_id' => $roleId,
            'scope_type' => 'team',
            'scope_id' => $team->id,
        ])->assertUnprocessable();

        // Even a stale/manual assignment must not grant team-management scope.
        $this->assign($supervisor, $roleId, 'team', $team->id);

        $this->assertFalse(app(AuthorizationService::class)->allowsFor($supervisor, 'access.audit', [
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ]));
    }

    public function test_role_scope_cannot_be_broadened_while_assignments_exist(): void
    {
        $organization = Organization::create(['name' => 'Immutable Role Scope Org', 'slug' => 'immutable-role-scope-org', 'status' => 'active']);
        $admin = $this->user('09140000128', User::ROLE_ADMIN);
        $manager = $this->user('09140000129', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $roleId = $this->role('immutable-org-scope-role', 'organization', $organization->id);
        $this->assign($manager, $roleId, 'organization', $organization->id);

        $this->actingAs($admin)->put('/admin/access-center/roles/'.$roleId, [
            'name' => 'Immutable scope role',
            'description' => 'Scope must remain stable while assigned',
            'scope_type' => 'global',
            'scope_id' => null,
            'is_active' => true,
        ])->assertStatus(409);

        $this->assertDatabaseHas('farast_roles', [
            'id' => $roleId,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
        ]);
    }

    public function test_admin_break_glass_honors_explicitly_narrowed_record_scope(): void
    {
        $admin = $this->user('09140000131', User::ROLE_ADMIN);
        $other = $this->user('09140000132');

        $this->actingAs($admin);
        $request = request();
        $request->setUserResolver(fn () => $admin);
        $request->attributes->set('farast.scope', 'own');

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allows($admin, 'documents.view'));
        $this->assertTrue($authorization->allowsFor($admin, 'documents.view', ['owner_id' => $admin->id]));
        $this->assertFalse($authorization->allowsFor($admin, 'documents.view', ['owner_id' => $other->id]));
        $this->assertFalse($authorization->explain($admin, 'documents.view', ['owner_id' => $other->id])['allowed']);
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
        // Remove the legacy organization-wide grant so this test isolates the custom team-scoped grant.
        DB::table('role_permissions')->where('role', User::ROLE_ORGANIZATION_MANAGER)
            ->where('permission_id', $this->permissionId('users.view'))->delete();
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
        // A stale section permission must not override the explicit permission
        // requested by a downstream query.
        request()->attributes->set('farast.section_permission', 'reports.team');

        $console = app(AdminConsoleService::class);
        $visibleIds = $console->visibleUserIds($manager, 'users.view');

        $this->assertContains($manager->id, $visibleIds);
        $this->assertContains($memberA->id, $visibleIds);
        $this->assertNotContains($memberB->id, $visibleIds);
        $this->assertNotContains($unassigned->id, $visibleIds);
        $this->assertSame([$organization->id], $console->visibleOrganizationIds($manager, 'users.view'));
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
