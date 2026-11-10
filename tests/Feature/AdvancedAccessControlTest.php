<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
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

    public function test_record_level_legacy_grants_fail_closed_without_an_owner_attribute(): void
    {
        $employee = $this->user('09140000109');
        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->allows($employee, 'documents.view'));
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', ['status' => 'active']));
        $this->assertTrue($authorization->allowsFor($employee, 'documents.view', ['owner_id' => $employee->id, 'status' => 'active']));
        $this->assertFalse($authorization->allowsFor($employee, 'documents.view', ['owner_id' => $employee->id + 1, 'status' => 'active']));
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

    public function test_advanced_access_center_is_only_available_to_the_platform_admin(): void
    {
        $admin = $this->user('09140000107', User::ROLE_ADMIN);
        $employee = $this->user('09140000108', User::ROLE_EMPLOYEE);

        $this->actingAs($admin)->get('/admin/access-center')->assertOk();
        $this->actingAs($employee)->get('/admin/access-center')->assertForbidden();
    }
}
