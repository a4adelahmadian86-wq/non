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
        SiteSetting::write('gemini_model', 'private-scope-model');
        SiteSetting::write('gemini_api_key', 'private-scope-secret', true);
        \App\Models\PricingRule::create(['key' => 'scope-private-price', 'value' => 987654, 'label' => 'Private global price', 'active' => true]);
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
        $dashboard->assertViewHas('rules', fn ($rules) => $rules->isEmpty());
        $dashboard->assertViewHas('settings', fn (array $settings): bool => ($settings['gemini_model'] ?? null) === '');
        $dashboard->assertViewHas('secretStatus', fn (array $status): bool => $status === ['gemini' => false, 'files' => false, 'groq' => false]);
        $dashboard->assertViewHas('systemHealth', fn (array $health): bool => $health === []);
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
}
