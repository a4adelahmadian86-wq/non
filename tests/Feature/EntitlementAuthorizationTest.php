<?php
namespace Tests\Feature;

use App\Models\FarastProject;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = new User();
        $u->name = 'Entitlement QA';
        $u->email = 'entitlement-'.uniqid().'@example.test';
        $u->mobile = '09'.str_pad((string) random_int(100000000,999999999),9,'0');
        $u->password = bcrypt('secret');
        $u->save();
        return $u;
    }

    public function test_organization_entitlements_and_projects_cannot_cross_owner_boundaries(): void
    {
        $organizationA = \\App\\Models\\Organization::create(['name' => 'Entitlement Org A', 'slug' => 'entitlement-org-a', 'status' => 'active']);
        $organizationB = \\App\\Models\\Organization::create(['name' => 'Entitlement Org B', 'slug' => 'entitlement-org-b', 'status' => 'active']);

        $userA = $this->user();
        $userA->organization_id = $organizationA->id;
        $userA->save();
        $userB = $this->user();
        $userB->organization_id = $organizationB->id;
        $userB->save();

        \\App\\Models\\FarastEntitlement::create([
            'organization_id' => $organizationA->id,
            'user_id' => null,
            'capability_code' => 'organization.shared',
            'mode' => 'subscription',
            'status' => 'active',
            'quantity' => 5,
            'used_quantity' => 0,
            'starts_at' => now()->subMinute(),
        ]);

        $service = app(EntitlementService::class);
        $this->assertTrue($service->allows($userA, 'organization.shared'));
        $this->assertFalse($service->allows($userB, 'organization.shared'));
        $this->assertFalse($service->consume($userB, 'organization.shared')['ok']);
        $this->assertSame(0, (int) \\App\\Models\\FarastEntitlement::where('capability_code', 'organization.shared')->value('used_quantity'));

        $project = FarastProject::create([
            'user_id' => $userA->id, 'name' => 'Private project', 'project_type' => 'typing', 'workflow' => 'manual',
            'template_code' => 'simple_typing', 'status' => 'active', 'context' => [],
            'billing_state' => [], 'output_state' => [],
        ]);
        \\App\\Models\\FarastEntitlement::create([
            'user_id' => $userB->id, 'capability_code' => 'project.private',
            'mode' => 'project_purchase', 'status' => 'active', 'quantity' => 2,
            'used_quantity' => 0, 'starts_at' => now()->subMinute(),
        ]);

        $this->assertFalse($service->allows($userB, 'project.private', $project));
        $this->assertFalse($service->consume($userB, 'project.private', 1, $project)['ok']);
    }

    public function test_project_entitlement_is_server_authoritative_and_consumed_atomically(): void
    {
        $user = $this->user();
        $project = FarastProject::create([
            'user_id'=>$user->id,'name'=>'پروژه','project_type'=>'typing','workflow'=>'manual',
            'template_code'=>'simple_typing','status'=>'active','context'=>['editor_type'=>'word_processor'],
            'billing_state'=>[],'output_state'=>[],
        ]);

        $service = app(EntitlementService::class);
        $service->grant('human.assistance', $user->id, $project->id, 'project_purchase', 2);

        $this->assertTrue($service->allows($user, 'human.assistance', $project));
        $this->assertTrue($service->consume($user, 'human.assistance', 1, $project)['ok']);
        $this->assertTrue($service->allows($user, 'human.assistance', $project));
        $this->assertFalse($service->consume($user, 'human.assistance', 2, $project)['ok']);
        $this->assertTrue($service->consume($user, 'human.assistance', 1, $project)['ok']);
        $this->assertFalse($service->allows($user, 'human.assistance', $project));
    }
}
