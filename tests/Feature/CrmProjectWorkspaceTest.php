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
