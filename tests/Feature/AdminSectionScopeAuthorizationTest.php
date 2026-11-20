<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SiteSetting;
use App\Models\Team;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AdminConsoleService;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminSectionScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function makeUser(string $mobile, string $role, ?int $organizationId = null): User
    {
        return User::create([
            'name' => 'QA '.$mobile,
            'mobile' => $mobile,
            'email' => $mobile.'@example.test',
            'password' => 'secret',
            'role' => $role,
            'organization_id' => $organizationId,
        ]);
    }

    private function makePaidOrder(User $user, int $amount): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => $amount,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => $amount,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'scope-test'],
            'paid_at' => now(),
        ]);
    }

    public function test_employee_can_open_only_the_dashboard_sections_granted_to_their_role(): void
    {
        $employee = $this->makeUser('09123456781', User::ROLE_EMPLOYEE);

        $this->actingAs($employee)->get('/admin')->assertOk();
        $this->get('/admin/access')->assertForbidden();
        $this->post('/admin/settings', ['weekly_free_pages' => 99])->assertForbidden();

        $this->assertDatabaseMissing('site_settings', ['key' => 'weekly_free_pages']);
    }

    public function test_organization_manager_sees_only_organization_financial_and_operational_data(): void
    {
        $organizationA = Organization::create(['name' => 'Org A', 'slug' => 'org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Org B', 'slug' => 'org-b', 'status' => 'active']);

        $manager = $this->makeUser('09123456782', User::ROLE_ORGANIZATION_MANAGER, $organizationA->id);
        $member = $this->makeUser('09123456783', User::ROLE_EMPLOYEE, $organizationA->id);
        $outsider = $this->makeUser('09123456784', User::ROLE_EMPLOYEE, $organizationB->id);

        Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Team A',
            'slug' => 'team-a',
            'supervisor_id' => $manager->id,
        ]);
        Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Team B',
            'slug' => 'team-b',
            'supervisor_id' => $outsider->id,
        ]);

        $scopedOrder = $this->makePaidOrder($member, 1000);
        Payment::create([
            'order_id' => $scopedOrder->id,
            'gateway' => 'test',
            'transaction_id' => 'SCOPE-IN',
            'amount_rials' => 1000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $otherOrder = $this->makePaidOrder($outsider, 9000);
        Payment::create([
            'order_id' => $otherOrder->id,
            'gateway' => 'test',
            'transaction_id' => 'SCOPE-OUT',
            'amount_rials' => 9000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Wallet::create(['user_id' => $member->id, 'balance_rials' => 300]);
        Wallet::create(['user_id' => $outsider->id, 'balance_rials' => 900]);

        DB::table('audit_logs')->insert([
            [
                'user_id' => $member->id,
                'action' => 'scope.in',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $outsider->id,
                'action' => 'scope.out',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $providerId = DB::table('voice_provider_accounts')->insertGetId([
            'provider' => 'scope-test',
            'name' => 'Global Voice Provider',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('voice_provider_usage')->insert([
            'voice_provider_account_id' => $providerId,
            'usage_date' => today()->toDateString(),
            'audio_seconds' => 120,
            'requests' => 7,
            'successful_requests' => 7,
            'failed_requests' => 0,
            'input_bytes' => 0,
            'output_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dashboard = $this->actingAs($manager)->get('/admin')->assertOk();
        $dashboard->assertViewHas('stats', function (array $stats): bool {
            return $stats['users'] === 2
                && $stats['organizations'] === 1
                && $stats['teams'] === 1
                && $stats['voice_requests'] === 0
                && $stats['voice_seconds'] === 0
                && $stats['audit_events'] === 1
                && $stats['revenue_total'] === 1000
                && $stats['wallet_balance'] === 300;
        });

        $organizations = $this->get('/admin/organizations')->assertOk();
        $organizations->assertViewHas('organizations', function ($paginator) use ($organizationA): bool {
            return $paginator->total() === 1
                && $paginator->first()?->id === $organizationA->id;
        });

        $analytics = $this->get('/admin/analytics')->assertOk();
        $analytics->assertViewHas('stats', function (array $stats): bool {
            return $stats['users'] === 2
                && $stats['active_users'] === 2
                && $stats['voice_requests'] === 0
                && $stats['voice_seconds'] === 0
                && $stats['revenue'] === 1000;
        });
        $analytics->assertViewHas('providers', fn ($providers) => $providers->isEmpty());

        $this->post('/admin/organizations', ['name' => 'Unauthorized Org', 'slug' => 'unauthorized-org'])
            ->assertForbidden();
        $this->assertDatabaseMissing('organizations', ['slug' => 'unauthorized-org']);

        $financePermission = Permission::query()->where('key', 'finance.view')->firstOrFail();
        RolePermission::firstOrCreate([
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'permission_id' => $financePermission->id,
        ]);
        $finance = $this->get('/admin/finance')->assertOk();
        $finance->assertViewHas('financeStats', fn (array $stats): bool => $stats['total'] === 1000);
        $finance->assertViewHas('walletTotal', 300);
        $finance->assertViewHas('users', fn ($users) => $users->count() === 2);

        $this->post('/admin/finance', [
            'tax_rate_percent' => 99,
            'payment_gateway' => 'none',
        ])->assertForbidden();
        $this->post('/admin/finance/users/'.$member->id.'/wallet', [
            'amount' => 100,
            'reason' => 'unauthorized test',
        ])->assertForbidden();

        $this->get('/admin/access')->assertForbidden();
    }

    public function test_team_supervisor_analytics_is_limited_to_managed_team_members(): void
    {
        $organizationA = Organization::create(['name' => 'Team Org A', 'slug' => 'team-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Team Org B', 'slug' => 'team-org-b', 'status' => 'active']);

        $supervisor = $this->makeUser('09123456788', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $member = $this->makeUser('09123456789', User::ROLE_EMPLOYEE, $organizationA->id);
        $otherSupervisor = $this->makeUser('09123456790', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);
        $otherMember = $this->makeUser('09123456791', User::ROLE_EMPLOYEE, $organizationB->id);

        $teamA = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Managed Team',
            'slug' => 'managed-team',
            'supervisor_id' => $supervisor->id,
        ]);
        $teamA->members()->attach($member->id, ['team_role' => 'employee']);

        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Other Team',
            'slug' => 'other-team',
            'supervisor_id' => $otherSupervisor->id,
        ]);
        $teamB->members()->attach($otherMember->id, ['team_role' => 'employee']);

        $insideOrder = $this->makePaidOrder($member, 1200);
        Payment::create([
            'order_id' => $insideOrder->id,
            'gateway' => 'test',
            'transaction_id' => 'TEAM-SCOPE-IN',
            'amount_rials' => 1200,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $outsideOrder = $this->makePaidOrder($otherMember, 8400);
        Payment::create([
            'order_id' => $outsideOrder->id,
            'gateway' => 'test',
            'transaction_id' => 'TEAM-SCOPE-OUT',
            'amount_rials' => 8400,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $analytics = $this->actingAs($supervisor)->get('/admin/analytics')->assertOk();
        $analytics->assertViewHas('stats', function (array $stats): bool {
            return $stats['users'] === 2 && $stats['revenue'] === 1200;
        });

        $this->get('/admin/organizations')->assertForbidden();
    }

    public function test_global_admin_scope_override_restricts_both_queries_and_global_sections(): void
    {
        $organization = Organization::create(['name' => 'Restricted Org', 'slug' => 'restricted-org', 'status' => 'active']);
        $otherOrganization = Organization::create(['name' => 'Other Org', 'slug' => 'other-org', 'status' => 'active']);
        $admin = $this->makeUser('09123456785', User::ROLE_ADMIN, $organization->id);
        $insideUser = $this->makeUser('09123456786', User::ROLE_EMPLOYEE, $organization->id);
        $outsideUser = $this->makeUser('09123456787', User::ROLE_EMPLOYEE, $otherOrganization->id);

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $dashboard = $this->actingAs($admin)->get('/admin')->assertOk();
        $dashboard->assertViewHas('scope', 'organization');
        $dashboard->assertViewHas('stats', fn (array $stats): bool => $stats['users'] === 1);

        $this->get('/admin/access')->assertForbidden();

        $this->post('/admin/users/'.$outsideUser->id.'/block')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $outsideUser->id, 'is_blocked' => false]);

        $this->post('/admin/users/'.$insideUser->id.'/block')->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $insideUser->id, 'is_blocked' => true]);

        $this->post('/admin/users/'.$insideUser->id.'/role', ['role' => User::ROLE_ADMIN])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $insideUser->id, 'role' => User::ROLE_EMPLOYEE]);
    }
    private function assignScopedAdminRole(
        User $admin,
        string $roleKey,
        array $permissionKeys,
        string $scope,
        ?int $organizationId = null,
        ?int $teamId = null,
    ): void {
        $roleId = DB::table('roles')->insertGetId([
            'key' => $roleKey,
            'name' => 'Scoped admin '.$roleKey,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
            RolePermission::firstOrCreate([
                'role' => $roleKey,
                'permission_id' => $permission->id,
            ]);
        }

        DB::table('users')->where('id', $admin->id)->update([
            'access_control_managed' => true,
            'updated_at' => now(),
        ]);

        DB::table('role_assignments')->insert([
            'user_id' => $admin->id,
            'role_id' => $roleId,
            'scope' => $scope,
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'assigned_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_organization_assignment_is_the_source_of_scope_without_user_organization_id(): void
    {
        $organizationA = Organization::create(['name' => 'Assigned Admin Org A', 'slug' => 'assigned-admin-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Assigned Admin Org B', 'slug' => 'assigned-admin-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456901', User::ROLE_ADMIN);
        $memberA = $this->makeUser('09123456902', User::ROLE_EMPLOYEE, $organizationA->id);
        $memberB = $this->makeUser('09123456903', User::ROLE_EMPLOYEE, $organizationB->id);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-organization-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organizationA->id,
        );

        $response = $this->actingAs($admin)->get('/admin')->assertOk();
        $response->assertViewHas('scope', 'organization');
        $response->assertViewHas('stats', fn (array $stats): bool => $stats['users'] === 1);
        $response->assertViewHas('users', fn ($users): bool => $users->contains('id', $memberA->id)
            && ! $users->contains('id', $memberB->id));
    }

    public function test_admin_team_assignment_is_the_source_of_scope_without_team_membership(): void
    {
        $organizationA = Organization::create(['name' => 'Assigned Admin Team Org A', 'slug' => 'assigned-admin-team-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Assigned Admin Team Org B', 'slug' => 'assigned-admin-team-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456911', User::ROLE_ADMIN);
        $memberA = $this->makeUser('09123456912', User::ROLE_EMPLOYEE, $organizationA->id);
        $memberB = $this->makeUser('09123456913', User::ROLE_EMPLOYEE, $organizationB->id);
        $supervisorA = $this->makeUser('09123456914', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $supervisorB = $this->makeUser('09123456915', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);

        $teamA = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Assigned Team A',
            'slug' => 'assigned-admin-team-a',
            'supervisor_id' => $supervisorA->id,
        ]);
        $teamA->members()->attach($memberA->id, ['team_role' => 'employee']);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Assigned Team B',
            'slug' => 'assigned-admin-team-b',
            'supervisor_id' => $supervisorB->id,
        ]);
        $teamB->members()->attach($memberB->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-team-test',
            ['dashboard.view', 'users.view'],
            'team',
            $organizationA->id,
            $teamA->id,
        );

        $response = $this->actingAs($admin)->get('/admin')->assertOk();
        $response->assertViewHas('scope', 'team');
        $response->assertViewHas('stats', fn (array $stats): bool => $stats['users'] === 2);
        $response->assertViewHas('users', fn ($users): bool => $users->contains('id', $memberA->id)
            && ! $users->contains('id', $memberB->id));
    }

    public function test_team_assignment_cannot_escape_an_additional_organization_boundary(): void
    {
        $organizationA = Organization::create(['name' => 'Admin Boundary Org A', 'slug' => 'admin-boundary-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Admin Boundary Org B', 'slug' => 'admin-boundary-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456931', User::ROLE_ADMIN);
        $memberB = $this->makeUser('09123456932', User::ROLE_EMPLOYEE, $organizationB->id);
        $supervisorB = $this->makeUser('09123456933', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Outside Assigned Org Team',
            'slug' => 'outside-assigned-org-team',
            'supervisor_id' => $supervisorB->id,
        ]);
        $teamB->members()->attach($memberB->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-org-boundary-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organizationA->id,
        );
        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-outside-team-test',
            ['dashboard.view', 'users.view'],
            'team',
            $organizationB->id,
            $teamB->id,
        );

        $response = $this->actingAs($admin)->get('/admin')->assertOk();
        $response->assertViewHas('scope', 'own');
        $response->assertViewHas('stats', fn (array $stats): bool => $stats['users'] === 1);
        $response->assertViewHas('users', fn ($users): bool => $users->count() === 0
            || ($users->count() === 1 && (int) $users->first()->id === (int) $admin->id));
        $this->assertNotContains($memberB->id, $response->viewData('users')->pluck('id')->all());
    }

    public function test_scoped_admin_can_assign_roles_only_inside_assigned_organization(): void
    {
        $organizationA = Organization::create(['name' => 'Manage Scope Org A', 'slug' => 'manage-scope-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Manage Scope Org B', 'slug' => 'manage-scope-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456921', User::ROLE_ADMIN);
        $memberA = $this->makeUser('09123456922', User::ROLE_EMPLOYEE, $organizationA->id);
        $memberB = $this->makeUser('09123456923', User::ROLE_EMPLOYEE, $organizationB->id);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-access-manager-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organizationA->id,
        );

        $accessPermission = Permission::query()->where('key', 'access.manage')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            'user_id' => $admin->id,
            'permission_id' => $accessPermission->id,
            'effect' => 'allow',
            'scope' => 'global',
            'organization_id' => null,
            'team_id' => null,
            'granted_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/access-control/users/'.$memberA->id.'/roles', [
            'role_key' => 'user',
            'scope' => 'own',
        ])->assertCreated();

        $this->assertDatabaseHas('role_assignments', [
            'user_id' => $memberA->id,
            'scope' => 'own',
        ]);

        $this->postJson('/admin/access-control/users/'.$memberB->id.'/roles', [
            'role_key' => 'user',
            'scope' => 'own',
        ])->assertForbidden();

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $memberB->id,
            'scope' => 'own',
        ]);
    }

    public function test_scoped_admin_cannot_use_team_management_grant_outside_organization_boundary(): void
    {
        $organizationA = Organization::create(['name' => 'Team Grant Boundary A', 'slug' => 'team-grant-boundary-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Team Grant Boundary B', 'slug' => 'team-grant-boundary-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456941', User::ROLE_ADMIN);
        $memberB = $this->makeUser('09123456942', User::ROLE_EMPLOYEE, $organizationB->id);
        $supervisorB = $this->makeUser('09123456943', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Team Grant Outside Org',
            'slug' => 'team-grant-outside-org',
            'supervisor_id' => $supervisorB->id,
        ]);
        $teamB->members()->attach($memberB->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-org-for-team-grant-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organizationA->id,
        );

        $accessPermission = Permission::query()->where('key', 'access.manage')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            'user_id' => $admin->id,
            'permission_id' => $accessPermission->id,
            'effect' => 'allow',
            'scope' => 'team',
            'organization_id' => $organizationB->id,
            'team_id' => $teamB->id,
            'granted_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/access-control/users/'.$memberB->id.'/roles', [
            'role_key' => 'user',
            'scope' => 'own',
        ])->assertForbidden();

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $memberB->id,
            'scope' => 'own',
        ]);
    }

    public function test_organization_management_grant_cannot_escape_narrower_team_assignment(): void
    {
        $organizationA = Organization::create(['name' => 'Narrow Team Assignment A', 'slug' => 'narrow-team-assignment-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Broad Org Grant B', 'slug' => 'broad-org-grant-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456951', User::ROLE_ADMIN);
        $memberA = $this->makeUser('09123456952', User::ROLE_EMPLOYEE, $organizationA->id);
        $supervisorA = $this->makeUser('09123456953', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $teamA = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Narrow Assigned Team',
            'slug' => 'narrow-assigned-team',
            'supervisor_id' => $supervisorA->id,
        ]);
        $teamA->members()->attach($memberA->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'narrow-team-assignment-test',
            ['dashboard.view', 'users.view'],
            'team',
            $organizationA->id,
            $teamA->id,
        );

        $accessPermission = Permission::query()->where('key', 'access.manage')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            'user_id' => $admin->id,
            'permission_id' => $accessPermission->id,
            'effect' => 'allow',
            'scope' => 'organization',
            'organization_id' => $organizationB->id,
            'team_id' => null,
            'granted_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->postJson('/admin/access-control/users/'.$memberA->id.'/roles', [
            'role_key' => 'user',
            'scope' => 'own',
        ])->assertForbidden();

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $memberA->id,
            'scope' => 'own',
        ]);
    }

    public function test_direct_record_grants_cannot_widen_scoped_admin_assignments(): void
    {
        $organizationA = Organization::create(['name' => 'Record Scope Org A', 'slug' => 'record-scope-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Record Scope Org B', 'slug' => 'record-scope-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456961', User::ROLE_ADMIN);
        $memberB = $this->makeUser('09123456962', User::ROLE_EMPLOYEE, $organizationB->id);
        $supervisorB = $this->makeUser('09123456963', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Record Scope Outside Team',
            'slug' => 'record-scope-outside-team',
            'supervisor_id' => $supervisorB->id,
        ]);
        $teamB->members()->attach($memberB->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-record-access-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organizationA->id,
        );

        $usersView = Permission::query()->where('key', 'users.view')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            [
                'user_id' => $admin->id,
                'permission_id' => $usersView->id,
                'effect' => 'allow',
                'scope' => 'global',
                'organization_id' => null,
                'team_id' => null,
                'granted_by' => null,
                'starts_at' => now()->subMinute(),
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $admin->id,
                'permission_id' => $usersView->id,
                'effect' => 'allow',
                'scope' => 'team',
                'organization_id' => $organizationB->id,
                'team_id' => $teamB->id,
                'granted_by' => null,
                'starts_at' => now()->subMinute(),
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $recordA = new class extends \Illuminate\Database\Eloquent\Model {};
        $recordA->setAttribute('organization_id', $organizationA->id);
        $recordB = new class extends \Illuminate\Database\Eloquent\Model {};
        $recordB->setAttribute('organization_id', $organizationB->id);
        $recordB->setAttribute('team_id', $teamB->id);
        $recordB->setAttribute('user_id', $memberB->id);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allowsRecord($admin, 'users.view', $recordA));
        $this->assertFalse($authorization->allowsRecord($admin, 'users.view', $recordB));
    }

    public function test_team_scoped_direct_grant_checks_the_target_users_team_membership(): void
    {
        $organizationA = Organization::create(['name' => 'Team Record Org A', 'slug' => 'team-record-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Team Record Org B', 'slug' => 'team-record-org-b', 'status' => 'active']);
        $admin = $this->makeUser('09123456971', User::ROLE_ADMIN);
        $memberA = $this->makeUser('09123456972', User::ROLE_EMPLOYEE, $organizationA->id);
        $memberB = $this->makeUser('09123456973', User::ROLE_EMPLOYEE, $organizationB->id);
        $supervisorA = $this->makeUser('09123456974', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $supervisorB = $this->makeUser('09123456975', User::ROLE_TEAM_SUPERVISOR, $organizationB->id);

        $teamA = Team::create([
            'organization_id' => $organizationA->id,
            'name' => 'Team Record A',
            'slug' => 'team-record-a',
            'supervisor_id' => $supervisorA->id,
        ]);
        $teamA->members()->attach($memberA->id, ['team_role' => 'employee']);
        $teamB = Team::create([
            'organization_id' => $organizationB->id,
            'name' => 'Team Record B',
            'slug' => 'team-record-b',
            'supervisor_id' => $supervisorB->id,
        ]);
        $teamB->members()->attach($memberB->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-team-record-test',
            ['dashboard.view'],
            'team',
            $organizationA->id,
            $teamA->id,
        );

        $usersView = Permission::query()->where('key', 'users.view')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            'user_id' => $admin->id,
            'permission_id' => $usersView->id,
            'effect' => 'allow',
            'scope' => 'team',
            'organization_id' => $organizationA->id,
            'team_id' => $teamA->id,
            'granted_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->allowsRecord($admin, 'users.view', $memberA));
        $this->assertFalse($authorization->allowsRecord($admin, 'users.view', $memberB));
    }

    public function test_global_deny_grant_overrides_scoped_allow_within_assigned_organization(): void
    {
        $organization = Organization::create(['name' => 'Deny Precedence Org', 'slug' => 'deny-precedence-org', 'status' => 'active']);
        $admin = $this->makeUser('09123456981', User::ROLE_ADMIN);

        $this->assignScopedAdminRole(
            $admin,
            'scoped-admin-deny-precedence-test',
            ['dashboard.view', 'users.view'],
            'organization',
            $organization->id,
        );

        $usersView = Permission::query()->where('key', 'users.view')->firstOrFail();
        DB::table('user_permission_grants')->insert([
            'user_id' => $admin->id,
            'permission_id' => $usersView->id,
            'effect' => 'deny',
            'scope' => 'global',
            'organization_id' => null,
            'team_id' => null,
            'granted_by' => null,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = new class extends \Illuminate\Database\Eloquent\Model {};
        $record->setAttribute('organization_id', $organization->id);

        $this->assertFalse(app(AuthorizationService::class)->allowsRecord($admin, 'users.view', $record));
    }

    public function test_store_management_requires_global_store_section_access(): void
    {
        $organization = Organization::create(['name' => 'Store Scope Org', 'slug' => 'store-scope-org', 'status' => 'active']);
        $employee = $this->makeUser('09123456792', User::ROLE_EMPLOYEE, $organization->id);
        $manager = $this->makeUser('09123456793', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $storePermission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        RolePermission::firstOrCreate(['role' => User::ROLE_ORGANIZATION_MANAGER, 'permission_id' => $storePermission->id]);

        $this->actingAs($employee)->get('/admin/store')->assertForbidden();
        $this->actingAs($manager)->get('/admin/store')->assertForbidden();
    }

    public function test_product_operations_and_support_report_require_global_admin_sections(): void
    {
        $organization = Organization::create(['name' => 'Restricted Admin Org', 'slug' => 'restricted-admin-org', 'status' => 'active']);
        $employee = $this->makeUser('09123456794', User::ROLE_EMPLOYEE, $organization->id);
        $manager = $this->makeUser('09123456795', User::ROLE_ORGANIZATION_MANAGER, $organization->id);
        $storePermission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        $supportPermission = Permission::query()->where('key', 'support.manage')->firstOrFail();
        RolePermission::firstOrCreate(['role' => User::ROLE_ORGANIZATION_MANAGER, 'permission_id' => $storePermission->id]);
        RolePermission::firstOrCreate(['role' => User::ROLE_ORGANIZATION_MANAGER, 'permission_id' => $supportPermission->id]);

        $this->actingAs($employee)->get('/admin/store/operations')->assertForbidden();
        $this->actingAs($manager)->get('/admin/store/operations')->assertForbidden();
        $this->actingAs($employee)->get('/admin/support-report')->assertForbidden();
        $this->actingAs($manager)->get('/admin/support-report')->assertForbidden();
    }


    public function test_direct_own_grant_cannot_unlock_an_organization_scoped_admin_section(): void
    {
        $organization = Organization::create(['name' => 'Direct Grant Org', 'slug' => 'direct-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123457001', User::ROLE_ADMIN);
        $manager = $this->makeUser('09123457002', User::ROLE_ORGANIZATION_MANAGER, $organization->id);

        config(['farast_admin.sections.direct_grant_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'organization',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->grantDirectPermission($root, $manager, 'store.manage', 'allow', 'own');

        $this->assertTrue($authorization->allows($manager, 'store.manage'));
        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($manager, 'direct_grant_scope_test'));

        $authorization->grantDirectPermission(
            $root,
            $manager,
            'store.manage',
            'allow',
            'organization',
            $organization->id,
        );

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($manager, 'direct_grant_scope_test'));
    }

    public function test_organization_grants_must_cover_every_visible_organization(): void
    {
        $organizationA = Organization::create(['name' => 'Org Grant A', 'slug' => 'org-grant-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Org Grant B', 'slug' => 'org-grant-b', 'status' => 'active']);
        $root = $this->makeUser('09123457003', User::ROLE_ADMIN);
        $admin = $this->makeUser('09123457004', User::ROLE_ADMIN);

        $this->assignScopedAdminRole($admin, 'multi-org-dashboard-a', ['dashboard.view'], 'organization', $organizationA->id);
        $this->assignScopedAdminRole($admin, 'multi-org-dashboard-b', ['dashboard.view'], 'organization', $organizationB->id);

        config(['farast_admin.sections.multi_org_direct_grant_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'organization',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->grantDirectPermission($root, $admin, 'store.manage', 'allow', 'organization', $organizationA->id);

        $this->assertTrue($authorization->allows($admin, 'store.manage'));
        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($admin, 'multi_org_direct_grant_test'));

        $authorization->grantDirectPermission($root, $admin, 'store.manage', 'allow', 'organization', $organizationB->id);

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($admin, 'multi_org_direct_grant_test'));
    }

    public function test_team_scoped_section_grants_must_cover_every_visible_team(): void
    {
        $organization = Organization::create(['name' => 'Multi Team Grant Org', 'slug' => 'multi-team-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123457005', User::ROLE_ADMIN);
        $supervisor = $this->makeUser('09123457006', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $teamA = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Grant Team A',
            'slug' => 'grant-team-a',
            'supervisor_id' => $supervisor->id,
        ]);
        $teamB = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Grant Team B',
            'slug' => 'grant-team-b',
            'supervisor_id' => $supervisor->id,
        ]);
        $supervisor->teams()->attach([
            $teamA->id => ['team_role' => 'manager'],
            $teamB->id => ['team_role' => 'manager'],
        ]);

        config(['farast_admin.sections.direct_team_grant_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'team',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->grantDirectPermission($root, $supervisor, 'store.manage', 'allow', 'team', $organization->id, $teamA->id);

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'direct_team_grant_scope_test'));

        $authorization->grantDirectPermission($root, $supervisor, 'store.manage', 'allow', 'team', $organization->id, $teamB->id);

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'direct_team_grant_scope_test'));
    }

    public function test_organization_grant_does_not_cover_team_sections_spanning_another_organization(): void
    {
        $organizationA = Organization::create(['name' => 'Grant Org A', 'slug' => 'grant-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Grant Org B', 'slug' => 'grant-org-b', 'status' => 'active']);
        $root = $this->makeUser('09123457007', User::ROLE_ADMIN);
        $admin = $this->makeUser('09123457008', User::ROLE_ADMIN);
        $teamA = Team::create(['organization_id' => $organizationA->id, 'name' => 'Org A Team', 'slug' => 'org-a-team']);
        $teamB = Team::create(['organization_id' => $organizationB->id, 'name' => 'Org B Team', 'slug' => 'org-b-team']);

        $this->assignScopedAdminRole($admin, 'cross-org-team-a', ['dashboard.view'], 'team', $organizationA->id, $teamA->id);
        $this->assignScopedAdminRole($admin, 'cross-org-team-b', ['dashboard.view'], 'team', $organizationB->id, $teamB->id);

        config(['farast_admin.sections.cross_org_team_grant_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'team',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->grantDirectPermission($root, $admin, 'store.manage', 'allow', 'organization', $organizationA->id);

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($admin, 'cross_org_team_grant_test'));

        $authorization->grantDirectPermission($root, $admin, 'store.manage', 'allow', 'organization', $organizationB->id);

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($admin, 'cross_org_team_grant_test'));
    }

    public function test_team_grant_cannot_unlock_an_own_scope_section_with_unbounded_owner_records(): void
    {
        $organization = Organization::create(['name' => 'Own Scope Grant Org', 'slug' => 'own-scope-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123457009', User::ROLE_ADMIN);
        $employee = $this->makeUser('09123457010', User::ROLE_EMPLOYEE, $organization->id);
        $team = Team::create(['organization_id' => $organization->id, 'name' => 'Own Scope Team', 'slug' => 'own-scope-team']);
        $employee->teams()->attach($team->id, ['team_role' => 'employee']);

        config(['farast_admin.sections.team_grant_own_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'own',
        ]]);

        app(AuthorizationService::class)->grantDirectPermission(
            $root,
            $employee,
            'store.manage',
            'allow',
            'team',
            $organization->id,
            $team->id,
        );

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($employee, 'team_grant_own_scope_test'));
    }

    public function test_role_assignment_permission_must_cover_every_visible_team(): void
    {
        $organization = Organization::create(['name' => 'Role Scope Org', 'slug' => 'role-scope-org', 'status' => 'active']);
        $supervisor = $this->makeUser('09123457011', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $teamA = Team::create(['organization_id' => $organization->id, 'name' => 'Role Team A', 'slug' => 'role-team-a']);
        $teamB = Team::create(['organization_id' => $organization->id, 'name' => 'Role Team B', 'slug' => 'role-team-b']);
        $supervisor->teams()->attach([
            $teamA->id => ['team_role' => 'manager'],
            $teamB->id => ['team_role' => 'manager'],
        ]);

        $storePermission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        $this->assignScopedAdminRole($supervisor, 'team-store-operator-a', ['store.manage'], 'team', $organization->id, $teamA->id);
        $this->assignScopedAdminRole($supervisor, 'team-store-operator-b', [], 'team', $organization->id, $teamB->id);

        config(['farast_admin.sections.team_role_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'team',
        ]]);

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'team_role_scope_test'));

        RolePermission::firstOrCreate(['role' => 'team-store-operator-b', 'permission_id' => $storePermission->id]);

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'team_role_scope_test'));
    }



    public function test_invalid_explicit_team_assignment_does_not_fall_back_to_legacy_managed_teams(): void
    {
        $organization = Organization::create(['name' => 'Invalid Team Scope Org', 'slug' => 'invalid-team-scope-org', 'status' => 'active']);
        $actor = $this->makeUser('09123457012', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $target = $this->makeUser('09123457013', User::ROLE_EMPLOYEE, $organization->id);
        $unmanagedAssignedTeam = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Explicit But Unmanaged Team',
            'slug' => 'explicit-but-unmanaged-team',
        ]);
        $legacyManagedTeam = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Legacy Managed Team',
            'slug' => 'legacy-managed-team',
            'supervisor_id' => $actor->id,
        ]);
        $legacyManagedTeam->members()->attach($target->id, ['team_role' => 'employee']);

        $this->assignScopedAdminRole(
            $actor,
            'invalid-explicit-team-access-manager',
            ['access.manage'],
            'team',
            $organization->id,
            $unmanagedAssignedTeam->id,
        );

        $this->actingAs($actor)->postJson('/admin/access-control/users/'.$target->id.'/roles', [
            'role_key' => 'user',
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $legacyManagedTeam->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('role_assignments', [
            'user_id' => $target->id,
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $legacyManagedTeam->id,
        ]);
    }



    public function test_direct_team_grant_can_target_an_admin_with_explicit_team_assignment(): void
    {
        $organization = Organization::create(['name' => 'Admin Team Grant Org', 'slug' => 'admin-team-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123457014', User::ROLE_ADMIN);
        $admin = $this->makeUser('09123457015', User::ROLE_ADMIN);
        $team = Team::create(['organization_id' => $organization->id, 'name' => 'Explicit Admin Team', 'slug' => 'explicit-admin-team']);

        $this->assignScopedAdminRole($admin, 'explicit-admin-team-viewer', ['dashboard.view'], 'team', $organization->id, $team->id);

        app(AuthorizationService::class)->grantDirectPermission(
            $root,
            $admin,
            'store.manage',
            'allow',
            'team',
            $organization->id,
            $team->id,
        );

        $this->assertDatabaseHas('user_permission_grants', [
            'user_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', 'store.manage')->value('id'),
            'effect' => 'allow',
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ]);
    }



    public function test_store_admin_routes_require_global_store_section_even_when_multi_role_console_is_enabled(): void
    {
        $organization = Organization::create([
            'name' => 'Store Scope Org',
            'slug' => 'store-scope-org',
            'status' => 'active',
        ]);
        $manager = $this->makeUser('09123456785', User::ROLE_ORGANIZATION_MANAGER, $organization->id);

        $storePermission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        RolePermission::firstOrCreate([
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'permission_id' => $storePermission->id,
        ]);

        // Even an explicit role grant cannot widen a section configured as global-only.
        $this->actingAs($manager)->get('/admin/store')->assertForbidden();
        $this->get('/admin/store/operations')->assertForbidden();
        $operationsRoute = Route::getRoutes()->getByName('admin.store.operations');
        $this->assertNotNull($operationsRoute);
        $this->assertContains('admin.section:store', $operationsRoute->gatherMiddleware());
        $this->post('/admin/store', ['name' => 'Must Not Be Created'])->assertForbidden();

        $employee = $this->makeUser('09123456786', User::ROLE_EMPLOYEE);
        $this->actingAs($employee)->get('/admin/store')->assertForbidden();
        $this->post('/admin/store', ['name' => 'Must Not Be Created Either'])->assertForbidden();

        $supportPermission = Permission::query()->where('key', 'support.manage')->firstOrFail();
        RolePermission::firstOrCreate([
            'role' => User::ROLE_ORGANIZATION_MANAGER,
            'permission_id' => $supportPermission->id,
        ]);
        $this->actingAs($manager)->get('/admin/support-report')->assertForbidden();

        $supportReportRoute = Route::getRoutes()->getByName('admin.support.report');
        $this->assertNotNull($supportReportRoute);
        $this->assertContains('admin.section:support_admin', $supportReportRoute->gatherMiddleware());
    }

}
