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
