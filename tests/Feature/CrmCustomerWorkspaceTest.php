<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmCustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    private function makeUser(string $mobile, string $role = User::ROLE_EMPLOYEE): User
    {
        return User::create([
            'name' => 'CRM '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_admin_can_view_customer_360_and_create_internal_note_and_follow_up(): void
    {
        $admin = $this->makeUser('09120000001', User::ROLE_ADMIN);
        $customer = $this->makeUser('09120000002');

        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 250000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 250000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'test'],
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)->getJson('/admin/crm/customers')
            ->assertOk()->assertJsonFragment(['id' => $customer->id]);

        $this->getJson('/admin/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('metrics.orders_count', 1)
            ->assertJsonPath('metrics.paid_revenue_rials', 250000)
            ->assertJsonPath('timeline.0.type', 'order');

        $this->postJson('/admin/crm/customers/'.$customer->id.'/notes', [
            'note' => 'مشتری برای سفارش بعدی تماس می‌خواهد.',
        ])->assertCreated();

        $this->assertDatabaseHas('crm_customer_notes', [
            'user_id' => $customer->id,
            'author_id' => $admin->id,
            'visibility' => 'internal',
        ]);

        $this->postJson('/admin/crm/customers/'.$customer->id.'/follow-ups', [
            'title' => 'تماس پیگیری',
            'priority' => 'high',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated();

        $taskId = DB::table('crm_follow_up_tasks')->where('customer_id', $customer->id)->value('id');
        $this->patchJson('/admin/crm/customers/'.$customer->id.'/follow-ups/'.$taskId, [
            'status' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('crm_follow_up_tasks', [
            'id' => $taskId,
            'status' => 'completed',
        ]);
    }

    public function test_employee_without_users_permission_cannot_access_crm(): void
    {
        $employee = $this->makeUser('09120000003');
        $customer = $this->makeUser('09120000004');

        $this->actingAs($employee)->getJson('/admin/crm/customers')->assertForbidden();
        $this->postJson('/admin/crm/customers/'.$customer->id.'/notes', ['note' => 'نباید ثبت شود'])
            ->assertForbidden();
        $this->assertDatabaseCount('crm_customer_notes', 0);
    }

    public function test_customer_detail_and_writes_respect_admin_scope(): void
    {
        $organizationA = \App\Models\Organization::create(['name' => 'CRM A', 'slug' => 'crm-a', 'status' => 'active']);
        $organizationB = \App\Models\Organization::create(['name' => 'CRM B', 'slug' => 'crm-b', 'status' => 'active']);
        $admin = $this->makeUser('09120000005', User::ROLE_ADMIN);
        $inside = $this->makeUser('09120000006');
        $outside = $this->makeUser('09120000007');

        $inside->organization_id = $organizationA->id;
        $inside->save();
        $outside->organization_id = $organizationB->id;
        $outside->save();

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->getJson('/admin/crm/customers/'.$outside->id)->assertForbidden();
        $this->postJson('/admin/crm/customers/'.$outside->id.'/notes', ['note' => 'خارج از دامنه'])
            ->assertForbidden();
        $this->assertDatabaseCount('crm_customer_notes', 0);
    }
}
