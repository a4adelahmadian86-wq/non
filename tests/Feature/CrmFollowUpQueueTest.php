<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmFollowUpQueueTest extends TestCase
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
            'name' => 'Follow-up '.$mobile, 'email' => $mobile.'@example.test',
            'mobile' => $mobile, 'password' => 'secret', 'role' => $role,
        ]);
    }

    public function test_admin_can_filter_overdue_followups_and_only_sees_in_scope_customers(): void
    {
        $orgA = Organization::create(['name' => 'Queue A', 'slug' => 'queue-a', 'status' => 'active']);
        $orgB = Organization::create(['name' => 'Queue B', 'slug' => 'queue-b', 'status' => 'active']);
        $admin = $this->user('09154440001', User::ROLE_ADMIN);
        $admin->organization_id = $orgA->id;
        $admin->save();
        $inside = $this->user('09154440002');
        $outside = $this->user('09154440003');
        $inside->organization_id = $orgA->id;
        $inside->save();
        $outside->organization_id = $orgB->id;
        $outside->save();

        DB::table('crm_follow_up_tasks')->insert([
            ['customer_id' => $inside->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id,
                'title' => 'پیگیری فوری', 'status' => 'open', 'priority' => 'high',
                'due_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $outside->id, 'assigned_to' => $admin->id, 'created_by' => $admin->id,
                'title' => 'خارج دامنه', 'status' => 'open', 'priority' => 'high',
                'due_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)->getJson('/admin/crm/follow-ups?status=open&priority=high&overdue=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.customer_id', $inside->id)
            ->assertJsonPath('data.data.0.title', 'پیگیری فوری');
    }

    public function test_employee_without_users_permission_cannot_access_followup_queue(): void
    {
        $employee = $this->user('09154440004');
        $this->actingAs($employee)->getJson('/admin/crm/follow-ups')->assertForbidden();
    }
}
