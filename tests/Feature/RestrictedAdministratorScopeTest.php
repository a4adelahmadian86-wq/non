<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveAdminScope;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\AdminConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestrictedAdministratorScopeTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $suffix): Organization
    {
        return Organization::create([
            'name' => 'سازمان '.$suffix,
            'slug' => 'restricted-admin-'.$suffix.'-'.strtolower(Str::random(8)),
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => null,
            'is_blocked' => false,
        ]);
    }

    private function employee(Organization $organization): User
    {
        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'organization_id' => $organization->id,
            'is_blocked' => false,
        ]);
    }

    private function createScopedRole(string $key, string $scope, ?int $scopeId): int
    {
        $roleId = DB::table('farast_roles')->insertGetId([
            'key' => $key,
            'name' => 'نقش آزمایشی '.$key,
            'description' => 'نقش محدود برای آزمون دامنه مدیر',
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'is_system' => false,
            'is_active' => true,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('key', 'users.view')->value('id');
        $this->assertNotNull($permissionId, 'users.view permission must be seeded before scoped administrator tests.');

        DB::table('farast_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'scope_type' => $scope,
            'conditions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function assignScopedRole(User $platformAdmin, User $restrictedAdmin, int $roleId, string $scope, ?int $scopeId): void
    {
        $this->actingAs($platformAdmin)
            ->post(route('admin.access-center.users.roles.store', $restrictedAdmin), [
                'role_id' => $roleId,
                'scope_type' => $scope,
                'scope_id' => $scopeId,
            ])
            ->assertRedirect();
    }

    public function test_global_admin_can_restrict_another_admin_to_one_organization(): void
    {
        $organizationA = $this->organization('a');
        $organizationB = $this->organization('b');
        $platformAdmin = $this->admin();
        $restrictedAdmin = $this->admin();
        $memberA = $this->employee($organizationA);
        $memberB = $this->employee($organizationB);

        $this->actingAs($platformAdmin)
            ->put(route('admin.access-center.users.admin-scope.update', $restrictedAdmin), [
                'scope_type' => 'organization',
                'scope_id' => $organizationA->id,
                'reason' => 'محدودسازی مدیر به سازمان الف',
            ])
            ->assertRedirect();

        $roleId = $this->createScopedRole('restricted-admin-org-'.$organizationA->id, 'organization', $organizationA->id);
        $this->assignScopedRole($platformAdmin, $restrictedAdmin, $roleId, 'organization', $organizationA->id);
        $restrictedAdmin->refresh();
        $this->actingAs($restrictedAdmin);
        $this->get('/dashboard')->assertOk();
        app(AuthorizationService::class)->forgetUser();

        $this->assertSame(ResolveAdminScope::SCOPE_ORGANIZATION, ResolveAdminScope::scopeFor($restrictedAdmin));
        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($restrictedAdmin, 'users'));
        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($restrictedAdmin, 'access'));
        $this->assertTrue(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $memberA->id,
            'organization_id' => $organizationA->id,
            'team_ids' => [],
        ]));
        $this->assertFalse(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $memberB->id,
            'organization_id' => $organizationB->id,
            'team_ids' => [],
        ]));
        $this->assertTrue(app(AuthorizationService::class)->allows($restrictedAdmin, 'users.view'));
        $this->assertEqualsCanonicalizing(
            [$memberA->id],
            ResolveAdminScope::visibleUserIds($restrictedAdmin)
        );

        $this->get(route('admin.access-center.index'))->assertForbidden();

        $this->assertDatabaseHas('farast_access_audit_logs', [
            'action' => 'admin.scope.updated',
            'subject_user_id' => $restrictedAdmin->id,
        ]);
    }

    public function test_team_scoped_admin_cannot_cross_team_or_organization_boundaries(): void
    {
        $organizationA = $this->organization('team-a');
        $organizationB = $this->organization('team-b');
        $teamA = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'تیم محدود',
            'slug' => 'restricted-team-'.strtolower(Str::random(8)),
        ]);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'تیم دیگر',
            'slug' => 'foreign-team-'.strtolower(Str::random(8)),
        ]);
        $memberA = $this->employee($organizationA);
        $memberB = $this->employee($organizationB);
        $memberA->teams()->attach($teamA->id, ['team_role' => 'member']);
        $memberB->teams()->attach($teamB->id, ['team_role' => 'member']);
        $platformAdmin = $this->admin();
        $restrictedAdmin = $this->admin();

        $this->actingAs($platformAdmin)
            ->put(route('admin.access-center.users.admin-scope.update', $restrictedAdmin), [
                'scope_type' => 'team',
                'scope_id' => $teamA->id,
                'reason' => 'محدودسازی مدیر به تیم مشخص',
            ])
            ->assertRedirect();

        $roleId = $this->createScopedRole('restricted-admin-team-'.$teamA->id, 'team', $teamA->id);
        $this->assignScopedRole($platformAdmin, $restrictedAdmin, $roleId, 'team', $teamA->id);
        $restrictedAdmin->refresh();
        $this->actingAs($restrictedAdmin);
        $this->get('/dashboard')->assertOk();
        app(AuthorizationService::class)->forgetUser();

        $this->assertTrue(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $memberA->id,
            'organization_id' => $organizationA->id,
            'team_id' => $teamA->id,
        ]));
        $this->assertFalse(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $memberB->id,
            'organization_id' => $organizationB->id,
            'team_id' => $teamB->id,
        ]));
        $this->assertFalse(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $memberA->id,
            'organization_id' => $organizationA->id,
            'team_id' => $teamB->id,
        ]));
    }

    public function test_expired_administrator_restriction_fails_closed_to_own_scope(): void
    {
        $organization = $this->organization('expired');
        $member = $this->employee($organization);
        $restrictedAdmin = $this->admin();

        DB::table('farast_user_admin_scopes')->insert([
            'user_id' => $restrictedAdmin->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'reason' => 'محدودیت آزمایشی منقضی‌شده',
            'starts_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'created_by' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $roleId = $this->createScopedRole('expired-admin-role-'.$restrictedAdmin->id, 'organization', $organization->id);
        DB::table('farast_user_roles')->insert([
            'user_id' => $restrictedAdmin->id,
            'role_id' => $roleId,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'starts_at' => now()->subDays(2),
            'expires_at' => null,
            'assigned_by' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->actingAs($restrictedAdmin);
        $this->get('/dashboard')->assertOk();
        app(AuthorizationService::class)->forgetUser();

        $this->assertSame(ResolveAdminScope::SCOPE_OWN, ResolveAdminScope::scopeFor($restrictedAdmin));
        $this->assertFalse(app(AuthorizationService::class)->allowsFor($restrictedAdmin, 'users.view', [
            'owner_id' => $member->id,
            'organization_id' => $organization->id,
            'team_ids' => [],
        ]));
        $this->assertFalse(app(AuthorizationService::class)->allows($restrictedAdmin, 'users.view'));
    }
}
