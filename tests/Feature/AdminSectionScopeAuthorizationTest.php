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
use App\Services\AuthorizationService;
use App\Services\AdminConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $root = $this->makeUser('09123456796', User::ROLE_ADMIN);
        $manager = $this->makeUser('09123456797', User::ROLE_ORGANIZATION_MANAGER, $organization->id);

        config(['farast_admin.sections.direct_grant_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'organization',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->grantDirectPermission($root, $manager, 'store.manage', 'allow', 'own');

        // The generic permission exists, but its own-only grant cannot authorize
        // an organization-wide section query.
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

    public function test_team_scoped_section_grants_must_cover_every_visible_team(): void
    {
        $organization = Organization::create(['name' => 'Multi Team Grant Org', 'slug' => 'multi-team-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123456798', User::ROLE_ADMIN);
        $supervisor = $this->makeUser('09123456799', User::ROLE_TEAM_SUPERVISOR, $organization->id);
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
        $authorization->grantDirectPermission(
            $root,
            $supervisor,
            'store.manage',
            'allow',
            'team',
            $organization->id,
            $teamA->id,
        );

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'direct_team_grant_scope_test'));

        $authorization->grantDirectPermission(
            $root,
            $supervisor,
            'store.manage',
            'allow',
            'team',
            $organization->id,
            $teamB->id,
        );

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'direct_team_grant_scope_test'));
    }


    public function test_organization_grant_does_not_cover_team_sections_spanning_another_organization(): void
    {
        $organizationA = Organization::create(['name' => 'Grant Org A', 'slug' => 'grant-org-a', 'status' => 'active']);
        $organizationB = Organization::create(['name' => 'Grant Org B', 'slug' => 'grant-org-b', 'status' => 'active']);
        $root = $this->makeUser('09123456800', User::ROLE_ADMIN);
        $supervisor = $this->makeUser('09123456801', User::ROLE_TEAM_SUPERVISOR, $organizationA->id);
        $teamA = Team::create(['organization_id' => $organizationA->id, 'name' => 'Org A Team', 'slug' => 'org-a-team']);
        $teamB = Team::create(['organization_id' => $organizationB->id, 'name' => 'Org B Team', 'slug' => 'org-b-team']);
        $supervisor->teams()->attach([
            $teamA->id => ['team_role' => 'manager'],
            $teamB->id => ['team_role' => 'manager'],
        ]);

        config(['farast_admin.sections.cross_org_team_grant_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'team',
        ]]);

        app(AuthorizationService::class)->grantDirectPermission(
            $root,
            $supervisor,
            'store.manage',
            'allow',
            'organization',
            $organizationA->id,
        );

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'cross_org_team_grant_test'));
    }

    public function test_team_grant_cannot_unlock_an_own_scope_section_with_unbounded_owner_records(): void
    {
        $organization = Organization::create(['name' => 'Own Scope Grant Org', 'slug' => 'own-scope-grant-org', 'status' => 'active']);
        $root = $this->makeUser('09123456802', User::ROLE_ADMIN);
        $employee = $this->makeUser('09123456803', User::ROLE_EMPLOYEE, $organization->id);
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
        $root = $this->makeUser('09123456804', User::ROLE_ADMIN);
        $supervisor = $this->makeUser('09123456805', User::ROLE_TEAM_SUPERVISOR, $organization->id);
        $teamA = Team::create(['organization_id' => $organization->id, 'name' => 'Role Team A', 'slug' => 'role-team-a']);
        $teamB = Team::create(['organization_id' => $organization->id, 'name' => 'Role Team B', 'slug' => 'role-team-b']);
        $supervisor->teams()->attach([
            $teamA->id => ['team_role' => 'manager'],
            $teamB->id => ['team_role' => 'manager'],
        ]);

        $storePermission = Permission::query()->where('key', 'store.manage')->firstOrFail();
        DB::table('roles')->insert([
            ['key' => 'team_store_operator', 'name' => 'Team store operator', 'is_system' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'team_limited_operator', 'name' => 'Team limited operator', 'is_system' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        RolePermission::firstOrCreate(['role' => 'team_store_operator', 'permission_id' => $storePermission->id]);

        config(['farast_admin.sections.team_role_scope_test' => [
            'permission' => 'store.manage',
            'min_scope' => 'team',
        ]]);

        $authorization = app(AuthorizationService::class);
        $authorization->assignRole($root, $supervisor, 'team_store_operator', 'team', $organization->id, $teamA->id);
        $authorization->assignRole($root, $supervisor, 'team_limited_operator', 'team', $organization->id, $teamB->id);

        $this->assertFalse(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'team_role_scope_test'));

        RolePermission::firstOrCreate(['role' => 'team_limited_operator', 'permission_id' => $storePermission->id]);

        $this->assertTrue(app(AdminConsoleService::class)->sectionAllowed($supervisor, 'team_role_scope_test'));
    }

}
