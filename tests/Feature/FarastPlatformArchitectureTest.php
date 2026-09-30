<?php
namespace Tests\Feature;
use App\Models\FarastProject;
use App\Models\FarastTool;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\FeedbackPipelineService;
use App\Services\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class FarastPlatformArchitectureTest extends TestCase {
 use RefreshDatabase;
 private function user():User{$u=new User();$u->name='Platform QA';$u->email='platform-'.uniqid().'@example.test';$u->mobile='09'.str_pad((string)random_int(100000000,999999999),9,'0');$u->password=bcrypt('secret');$u->save();return $u;}
 public function test_shared_tools_are_registered_once_and_available_to_multiple_applications():void{$registry=app(ToolRegistry::class);$ocr=$registry->tool('ocr.document');$this->assertNotNull($ocr);$this->assertTrue($registry->isAvailable('ocr.document','word_processor'));$this->assertTrue($registry->isAvailable('ocr.document','reader'));$this->assertSame(1,FarastTool::where('code','ocr.document')->count());}
 public function test_project_entitlement_can_grant_a_capability_without_frontend_flags():void{$u=$this->user();$project=FarastProject::create(['user_id'=>$u->id,'name'=>'تست','project_type'=>'typing','workflow'=>'manual','template_code'=>'simple_typing','status'=>'active','context'=>['editor_type'=>'word_processor'],'billing_state'=>[],'output_state'=>[]]);$service=app(EntitlementService::class);$this->assertFalse($service->allows($u,'human.assistance',$project));$service->grant('human.assistance',$u->id,$project->id,'project_purchase',1);$this->assertTrue($service->allows($u,'human.assistance',$project));}
 public function test_feedback_enters_raw_evidence_before_review():void{$u=$this->user();$feedback=\App\Models\AiFeedback::create(['user_id'=>$u->id,'type'=>'rating','rating'=>4,'note'=>'ثبت شد','context'=>['scope'=>'selection']]);$evidence=app(FeedbackPipelineService::class)->record($feedback,'ai.editor','1.0.0',['scope'=>'selection'],['rating'=>4]);$this->assertDatabaseHas('farast_feedback_evidence',['id'=>$evidence->id,'status'=>'raw']);$review=app(FeedbackPipelineService::class)->queueReview($evidence);$this->assertDatabaseHas('farast_review_cases',['id'=>$review,'status'=>'queued']);}
}