<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\TypingDocument;
use App\Models\FarastDocument;
use App\Services\EditorAiAssistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
class FarastEditorAgentTest extends TestCase{
 use RefreshDatabase;
 private function doc(User $u):array{
  $l=TypingDocument::create(['user_id'=>$u->id,'title'=>'Test','content'=>'<p>متن</p>','status'=>'draft','word_count'=>1]);
  $f=FarastDocument::create(['user_id'=>$u->id,'title'=>'Test','content'=>'<p>متن</p>','content_json'=>['schema'=>3,'type'=>'document','sections'=>[['id'=>'s','blocks'=>[['id'=>'b','type'=>'paragraph','runs'=>[['id'=>'r','text'=>'متن']]]]]]],'document_format'=>'farast-v1','page_settings'=>[],'revision'=>1,'status'=>'active']);
  $l->update(['farast_document_id'=>$f->id]);return[$l,$f];
 }
 public function test_formatting_intent_is_preview_only_until_accept():void{
  $u=User::factory()->create(['role'=>'employee']);[$l,$f]=$this->doc($u);
  $r=$this->actingAs($u)->postJson('/editor/agent/plan',['document_id'=>$l->id,'prompt'=>'این متن را پررنگ کن','selection'=>['block_id'=>'b','start'=>0,'end'=>3]]);
  $r->assertOk()->assertJsonPath('intent.operation','format_bold');$this->assertDatabaseHas('farast_agent_tasks',['document_id'=>$f->id,'status'=>'preview_ready']);
 }
 public function test_high_risk_requires_explicit_approval():void{
  $u=User::factory()->create(['role'=>'employee']);[$l,$f]=$this->doc($u);
  $r=$this->actingAs($u)->postJson('/editor/agent/plan',['document_id'=>$l->id,'prompt'=>'این متن را حذف کن','selection'=>['block_id'=>'b','start'=>0,'end'=>3]]);
  $r->assertOk()->assertJsonPath('status','awaiting_approval');
 }
 public function test_stale_revision_is_rejected_at_commit():void{
  $u=User::factory()->create(['role'=>'employee']);[$l,$f]=$this->doc($u);
  $this->mock(EditorAiAssistService::class,function($m){$m->shouldReceive('assist')->andReturn(['text'=>'متن جدید','provider'=>'test','model'=>'mock']);});
  $p=$this->actingAs($u)->postJson('/editor/agent/plan',['document_id'=>$l->id,'prompt'=>'این متن را بازنویسی کن','selection'=>['block_id'=>'b','start'=>0,'end'=>3]])->json();
  $f->update(['revision'=>2]);
  $c=$this->actingAs($u)->postJson('/editor/agent/tasks/'.$p['task_id'].'/commit',['base_revision'=>$p['base_revision'],'result_revision'=>2,'transactions'=>1,'changed_blocks'=>1,'changed_characters'=>3]);
  $c->assertStatus(409);
 }
 public function test_prompt_injection_document_text_is_data_not_authority():void{
  $u=User::factory()->create(['role'=>'employee']);[$l,$f]=$this->doc($u);
  $this->mock(EditorAiAssistService::class,function($m){$m->shouldReceive('assist')->once()->withArgs(function($op,$text,$ctx){return $op==='selection.rewrite' && str_contains($ctx['instruction'],'untrusted data');})->andReturn(['text'=>'متن رسمی','provider'=>'test','model'=>'mock']);});
  $r=$this->actingAs($u)->postJson('/editor/agent/plan',['document_id'=>$l->id,'prompt'=>'این متن را بازنویسی کن','selection'=>['block_id'=>'b','start'=>0,'end'=>3]]);
  $this->assertSame(200,$r->status(),json_encode($r->json(),JSON_UNESCAPED_UNICODE));$r->assertJsonPath('intent.operation','rewrite');
 }
}