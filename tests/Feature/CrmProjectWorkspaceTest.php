<?php

namespace Tests\Feature;

use App\Models\FarastProject;
use App\Models\Order;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmProjectWorkspaceTest extends TestCase
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
            'name' => 'Project CRM '.$mobile,
            'email' => $mobile.'@example.test',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_admin_can_list_and_inspect_customer_projects_with_documents_and_payments(): void
    {
        $admin = $this->makeUser('09123330001', User::ROLE_ADMIN);
        $customer = $this->makeUser('09123330002');

        $project = FarastProject::create([
            'user_id' => $customer->id,
            'name' => 'پروژه تایپ قرارداد',
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template_code' => 'standard',
            'status' => 'active',
            'context' => ['language' => 'fa'],
            'billing_state' => ['status' => 'paid'],
            'output_state' => ['status' => 'unlocked'],
            'estimated_pages' => 12,
            'used_pages' => 8,
            'estimated_price_rials' => 900000,
            'paid_rials' => 600000,
        ]);

        $document = TypingDocument::create([
            'user_id' => $customer->id,
            'project_id' => $project->id,
            'title' => 'قرارداد همکاری',
            'content' => 'متن آزمایشی',
            'page_count' => 8,
            'word_count' => 1200,
            'status' => 'paid',
            'price_rials' => 600000,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'document_id' => $document->id,
            'subtotal_rials' => 600000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 600000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'typing_final'],
            'paid_at' => now(),
        ]);

        DB::table('farast_human_tasks')->insert([
            'task_id' => (string) \Illuminate\Support\Str::uuid(),
            'capability' => 'typing',
            'project_id' => $project->id,
            'requester_id' => $customer->id,
            'specialist_id' => null,
            'sla' => json_encode(['resolve_hours' => 24]),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->getJson('/admin/crm/projects?project_type=typing')
            ->assertOk()
            ->assertJsonFragment(['id' => $project->id])
            ->assertJsonFragment(['typing_documents_count' => 1]);

        $this->getJson('/admin/crm/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('project.id', $project->id)
            ->assertJsonPath('orders.0.id', $order->id)
            ->assertJsonPath('financials.remaining_rials', 300000)
            ->assertJsonPath('human_tasks.0.capability', 'typing');
    }

    public function test_admin_can_assign_project_human_task_to_an_in_scope_specialist(): void
    {
        $admin = $this->makeUser('09123330005', User::ROLE_ADMIN);
        $customer = $this->makeUser('09123330006');
        $specialist = $this->makeUser('09123330007');

        $project = FarastProject::create([
            'user_id' => $customer->id,
            'name' => 'پروژه دارای کار تخصصی',
            'project_type' => 'typing',
            'status' => 'active',
            'estimated_pages' => 1,
        ]);

        $taskId = DB::table('farast_human_tasks')->insertGetId([
            'task_id' => (string) \Illuminate\Support\Str::uuid(),
            'capability' => 'typing',
            'project_id' => $project->id,
            'requester_id' => $customer->id,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/crm/projects/'.$project->id.'/tasks/'.$taskId.'/assign', [
                'specialist_id' => $specialist->id,
            ])
            ->assertOk()
            ->assertJsonPath('specialist_id', $specialist->id);

        $this->assertDatabaseHas('farast_human_tasks', [
            'id' => $taskId,
            'project_id' => $project->id,
            'specialist_id' => $specialist->id,
        ]);
    }

    public function test_project_task_cannot_be_assigned_to_a_specialist_outside_admin_scope(): void
    {
        $organizationA = \App\Models\Organization::create(['name' => 'Project Scope A', 'slug' => 'project-scope-a', 'status' => 'active']);
        $organizationB = \App\Models\Organization::create(['name' => 'Project Scope B', 'slug' => 'project-scope-b', 'status' => 'active']);
        $admin = $this->makeUser('09123330008', User::ROLE_ADMIN);
        $admin->organization_id = $organizationA->id;
        $admin->save();
        $customer = $this->makeUser('09123330009');
        $customer->organization_id = $organizationA->id;
        $customer->save();
        $specialist = $this->makeUser('09123330010');
        $specialist->organization_id = $organizationB->id;
        $specialist->save();

        $project = FarastProject::create([
            'user_id' => $customer->id,
            'name' => 'پروژه سازمانی',
            'project_type' => 'typing',
            'status' => 'active',
            'estimated_pages' => 1,
        ]);
        $taskId = DB::table('farast_human_tasks')->insertGetId([
            'task_id' => (string) \Illuminate\Support\Str::uuid(),
            'capability' => 'typing',
            'project_id' => $project->id,
            'requester_id' => $customer->id,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        \App\Models\SiteSetting::write('dashboard_scope_override', 'organization');

        $this->actingAs($admin)
            ->postJson('/admin/crm/projects/'.$project->id.'/tasks/'.$taskId.'/assign', [
                'specialist_id' => $specialist->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('farast_human_tasks', [
            'id' => $taskId,
            'specialist_id' => null,
        ]);
    }

    public function test_employee_without_users_permission_cannot_access_project_workspace(): void
    {
        $employee = $this->makeUser('09123330003');
        $customer = $this->makeUser('09123330004');
        $project = FarastProject::create([
            'user_id' => $customer->id,
            'name' => 'Private project',
            'project_type' => 'typing',
            'status' => 'active',
            'estimated_pages' => 1,
        ]);

        $this->actingAs($employee)->getJson('/admin/crm/projects')->assertForbidden();
        $this->getJson('/admin/crm/projects/'.$project->id)->assertForbidden();
    }
}
