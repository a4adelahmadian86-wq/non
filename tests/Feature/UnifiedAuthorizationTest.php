<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $attributes): Model
    {
        $record = new class extends Model {
            protected $guarded = [];
        };

        foreach ($attributes as $key => $value) {
            $record->setAttribute($key, $value);
        }

        return $record;
    }

    private function permission(string $key): Permission
    {
        return Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => 'test']);
    }

    public function test_blocked_users_never_receive_permissions(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_blocked' => true]);
        self::assertFalse(app(AuthorizationService::class)->allows($user, 'access.manage'));
    }

    public function test_direct_deny_overrides_legacy_role_permission(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $permission = $this->permission('documents.delete');
        RolePermission::firstOrCreate(['role' => 'employee', 'permission_id' => $permission->id]);

        DB::table('user_permission_grants')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
            'scope' => 'global',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertFalse(app(AuthorizationService::class)->allows($user, 'documents.delete'));
    }

    public function test_own_scope_rejects_another_users_record(): void
    {
        $owner = User::factory()->create(['role' => 'employee']);
        $other = User::factory()->create(['role' => 'employee']);
        $permission = $this->permission('documents.view');
        RolePermission::firstOrCreate(['role' => 'employee', 'permission_id' => $permission->id]);
        $record = $this->record(['user_id' => $other->id]);

        self::assertFalse(app(AuthorizationService::class)->allowsRecord($owner, 'documents.view', $record));
        self::assertTrue(app(AuthorizationService::class)->allowsRecord($other, 'documents.view', $record));
    }

    public function test_organization_scope_rejects_records_owned_by_another_organization(): void
    {
        $first = Organization::create(['name' => 'First', 'slug' => 'first', 'status' => 'active']);
        $second = Organization::create(['name' => 'Second', 'slug' => 'second', 'status' => 'active']);
        $manager = User::factory()->create(['role' => 'organization_manager', 'organization_id' => $first->id]);
        $member = User::factory()->create(['role' => 'employee', 'organization_id' => $second->id]);
        $permission = $this->permission('documents.view');
        RolePermission::firstOrCreate(['role' => 'organization_manager', 'permission_id' => $permission->id]);

        self::assertFalse(app(AuthorizationService::class)->allowsRecord(
            $manager,
            'documents.view',
            $this->record(['user_id' => $member->id]),
        ));
    }

    public function test_admin_with_a_limited_assignment_cannot_bypass_record_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'employee']);
        $permission = $this->permission('documents.view');
        $accessPermission = $this->permission('access.manage');
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'limited_admin',
            'name' => 'Limited administrator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate(['role' => 'limited_admin', 'permission_id' => $permission->id]);
        RolePermission::firstOrCreate(['role' => 'limited_admin', 'permission_id' => $accessPermission->id]);
        DB::table('role_assignments')->insert([
            'user_id' => $admin->id,
            'role_id' => $roleId,
            'scope' => 'own',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertFalse(app(AuthorizationService::class)->allowsRecord(
            $admin,
            'documents.view',
            $this->record(['user_id' => $other->id]),
        ));
    }

    public function test_team_assignment_limits_console_scope_and_record_access(): void
    {
        $organization = Organization::create(['name' => 'Scoped', 'slug' => 'scoped', 'status' => 'active']);
        $team = Team::create(['organization_id' => $organization->id, 'name' => 'Ops', 'slug' => 'ops']);
        $user = User::factory()->create(['role' => 'employee', 'organization_id' => $organization->id]);
        $user->teams()->attach($team->id, ['team_role' => 'employee']);
        $permission = $this->permission('reports.team');
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'team_reporter',
            'name' => 'Team reporter',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate(['role' => 'team_reporter', 'permission_id' => $permission->id]);
        DB::table('role_assignments')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame('team', app(AuthorizationService::class)->effectiveScope($user));
        self::assertSame([$team->id], app(AuthorizationService::class)->effectiveTeamIds($user));
        self::assertSame('team', \App\Http\Middleware\ResolveAdminScope::scopeFor($user));
        self::assertTrue(app(AuthorizationService::class)->allowsRecord(
            $user,
            'reports.team',
            $this->record(['team_id' => $team->id]),
        ));
        self::assertFalse(app(AuthorizationService::class)->allowsRecord(
            $user,
            'reports.team',
            $this->record(['team_id' => $team->id + 100]),
        ));
    }

    public function test_expired_direct_allow_does_not_grant_access(): void
    {
        $user = User::factory()->create(['role' => 'employee']);
        $permission = $this->permission('reports.view');

        DB::table('user_permission_grants')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
            'scope' => 'global',
            'expires_at' => now()->subSecond(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        self::assertFalse(app(AuthorizationService::class)->allows($user, 'reports.view'));
    }

    public function test_custom_role_assignment_is_resolved_and_expiry_is_enforced(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $permission = $this->permission('workflows.manage');
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'workflow_operator',
            'name' => 'Workflow operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate(['role' => 'workflow_operator', 'permission_id' => $permission->id]);
        DB::table('role_assignments')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'scope' => 'own',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertTrue(app(AuthorizationService::class)->allows($user, 'workflows.manage'));

        DB::table('role_assignments')->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);
        self::assertFalse(app(AuthorizationService::class)->allows($user, 'workflows.manage'));
    }

    public function test_team_scoped_access_manager_cannot_delegate_into_another_team(): void
    {
        $organization = Organization::create(['name' => 'Delegation', 'slug' => 'delegation', 'status' => 'active']);
        $teamA = Team::create(['organization_id' => $organization->id, 'name' => 'Team A', 'slug' => 'team-a']);
        $teamB = Team::create(['organization_id' => $organization->id, 'name' => 'Team B', 'slug' => 'team-b']);
        $manager = User::factory()->create(['role' => 'employee', 'organization_id' => $organization->id]);
        $memberA = User::factory()->create(['role' => 'employee', 'organization_id' => $organization->id]);
        $memberB = User::factory()->create(['role' => 'employee', 'organization_id' => $organization->id]);
        $manager->teams()->attach($teamA->id, ['team_role' => 'manager']);
        $memberA->teams()->attach($teamA->id, ['team_role' => 'employee']);
        $memberB->teams()->attach($teamB->id, ['team_role' => 'employee']);

        $accessPermission = $this->permission('access.manage');
        RolePermission::firstOrCreate(['role' => 'team_admin', 'permission_id' => $accessPermission->id]);
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'team_admin',
            'name' => 'Team administrator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->insert([
            'user_id' => $manager->id,
            'role_id' => $roleId,
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $teamA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorization = app(AuthorizationService::class);
        $assignment = $authorization->assignRole($manager, $memberA, 'employee', 'own');
        self::assertSame($memberA->id, (int) $assignment->user_id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $authorization->assignRole($manager, $memberB, 'employee', 'own');
    }

    public function test_direct_permission_grant_and_revocation_are_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = User::factory()->create(['role' => 'employee']);
        $this->permission('reports.view');
        $authorization = app(AuthorizationService::class);

        $grant = $authorization->grantDirectPermission(
            $admin,
            $subject,
            'reports.view',
            'allow',
            'global',
        );

        self::assertTrue($authorization->allows($subject, 'reports.view'));
        $authorization->revokeDirectPermission($admin, (int) $grant->id);
        self::assertFalse($authorization->allows($subject, 'reports.view'));
        $this->assertDatabaseHas('access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_id' => $subject->id,
            'event' => 'permission.direct_revoked',
        ]);
    }

    public function test_admin_can_create_and_assign_a_custom_role_with_an_audit_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subject = User::factory()->create(['role' => 'user']);
        $permission = $this->permission('workflows.manage');
        $authorization = app(AuthorizationService::class);

        $authorization->createRole($admin, 'workflow_operator', 'Workflow operator', ['workflows.manage']);
        $assignment = $authorization->assignRole(
            $admin,
            $subject,
            'workflow_operator',
            'own',
            expiresAt: now()->addDay(),
        );

        self::assertSame($subject->id, (int) $assignment->user_id);
        self::assertTrue($authorization->allows($subject, 'workflows.manage'));
        $this->assertDatabaseHas('access_audit_logs', [
            'actor_id' => $admin->id,
            'subject_id' => $subject->id,
            'event' => 'role.assignment.created',
        ]);
    }

    public function test_security_audit_is_persisted_with_actor_and_subject(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        $request = Request::create('/admin/access', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

        app(AuthorizationService::class)->audit(
            'role.assignment.created',
            $actor,
            $subject,
            null,
            ['role' => 'employee'],
            $request,
        );

        $this->assertDatabaseHas('access_audit_logs', [
            'actor_id' => $actor->id,
            'subject_id' => $subject->id,
            'event' => 'role.assignment.created',
            'ip_address' => '127.0.0.1',
        ]);
    }
}
