<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmManagementAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function user(string $mobile, string $role = User::ROLE_EMPLOYEE): User
    {
        return User::create([
            'name' => 'Analytics '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_crm_metrics_are_aggregated_only_for_visible_organization_customers(): void
    {
        $orgA = \App\Models\Organization::create(['name' => 'Analytics A', 'slug' => 'analytics-a', 'status' => 'active']);
        $orgB = \App\Models\Organization::create(['name' => 'Analytics B', 'slug' => 'analytics-b', 'status' => 'active']);
        $admin = $this->user('09153330001', User::ROLE_ADMIN);
        $admin->organization_id = $orgA->id;
        $admin->save();
        $inside = $this->user('09153330002');
        $outside = $this->user('09153330003');
        $inside->organization_id = $orgA->id;
        $inside->save();
        $outside->organization_id = $orgB->id;
        $outside->save();

        foreach ([$inside, $outside] as $customer) {
            Order::create([
                'user_id' => $customer->id, 'subtotal_rials' => 100000,
                'discount_rials' => 0, 'tax_rials' => 0, 'total_rials' => 100000,
                'status' => 'paid', 'pricing_snapshot' => ['kind' => 'test'], 'paid_at' => now(),
            ]);
        }

        DB::table('crm_follow_up_tasks')->insert([
            ['customer_id' => $inside->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id,
                'title' => 'پیگیری درون سازمان', 'status' => 'open', 'priority' => 'normal',
                'due_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $outside->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id,
                'title' => 'پیگیری خارج سازمان', 'status' => 'open', 'priority' => 'normal',
                'due_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->getJson('/admin/crm/analytics')
            ->assertOk()
            ->assertJsonPath('customers.total', 1)
            ->assertJsonPath('customers.paying', 1)
            ->assertJsonPath('commerce.paid_revenue_rials', 100000)
            ->assertJsonPath('operations.open_follow_ups', 1)
            ->assertJsonPath('operations.overdue_follow_ups', 1)
            ->assertJsonPath('top_customers.0.id', $inside->id);
    }

    public function test_employee_without_marketing_permission_cannot_read_crm_analytics(): void
    {
        $employee = $this->user('09153330004');
        $this->actingAs($employee)->getJson('/admin/crm/analytics')->assertForbidden();
    }
}
