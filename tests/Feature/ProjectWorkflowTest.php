<?php

namespace Tests\Feature;

use App\Models\FarastProject;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = new User();
        $u->name = 'Project QA';
        $u->email = 'project-'.uniqid().'@example.test';
        $u->mobile = '09'.str_pad((string) random_int(100000000,999999999),9,'0');
        $u->password = bcrypt('secret');
        $u->save();
        return $u;
    }

    public function test_project_creation_persists_structured_typing_context(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->postJson('/projects', [
            'project_type'=>'typing',
            'template'=>'simple_typing',
            'workflow'=>'source_file',
            'source_type'=>'handwritten',
            'language'=>'fa',
            'estimated_pages'=>2,
        ]);

        $response->assertOk()->assertJsonPath('context.workflow','source_file')->assertJsonPath('context.source_type','handwritten');
        $this->assertDatabaseHas('farast_projects', [
            'id'=>$response->json('project_id'),
            'user_id'=>$user->id,
            'project_type'=>'typing',
            'template_code'=>'simple_typing',
        ]);
    }

    public function test_initial_typing_prices_are_configurable_by_workflow(): void
    {
        $pricing = app(PricingService::class);
        $manual = $pricing->projectQuote('manual','',1);
        $voice = $pricing->projectQuote('voice','',1);
        $printed = $pricing->projectQuote('source_file','printed',1);
        $handwritten = $pricing->projectQuote('source_file','handwritten',1);
        $mixed = $pricing->projectQuote('source_file','mixed',1);

        $this->assertSame(300000,$manual['unit_price_rials']);
        $this->assertSame(450000,$voice['unit_price_rials']);
        $this->assertSame(330000,$printed['unit_price_rials']);
        $this->assertSame(500000,$handwritten['unit_price_rials']);
        $this->assertSame(600000,$mixed['unit_price_rials']);
        $this->assertSame(375000,$pricing->projectQuote('manual','',1,'',true)['price_rials']);
    }

    public function test_export_remains_locked_without_entitlement_or_payment(): void
    {
        $user = $this->user();
        $project = FarastProject::create([
            'user_id'=>$user->id,'name'=>'پروژه قفل','project_type'=>'typing','workflow'=>'manual',
            'template_code'=>'simple_typing','status'=>'active','context'=>['workflow'=>'manual','template'=>'simple_typing','template_settings'=>['paper'=>'A4']],
            'billing_state'=>['status'=>'payment_required','amount_remaining'=>300000],
            'output_state'=>['status'=>'locked','reason'=>'payment_required'],
            'estimated_pages'=>1,'estimated_price_rials'=>300000,
        ]);
        $doc = TypingDocument::create(['user_id'=>$user->id,'project_id'=>$project->id,'title'=>'قفل','content'=>'<p>سلام</p>','page_count'=>1,'status'=>'draft']);

        $this->actingAs($user)->post('/editor/export/pdf', ['document_id'=>$doc->id])->assertStatus(402);
    }
}
