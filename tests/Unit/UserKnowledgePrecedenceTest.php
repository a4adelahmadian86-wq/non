<?php
namespace Tests\Unit;
use App\Models\User;
use App\Models\UserKnowledge;
use App\Services\UserKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class UserKnowledgePrecedenceTest extends TestCase {
 use RefreshDatabase;
 public function test_project_knowledge_overrides_confirmed_global_preference():void{$u=new User();$u->name='Knowledge QA';$u->email='knowledge-'.uniqid().'@example.test';$u->mobile='09'.str_pad((string)random_int(100000000,999999999),9,'0');$u->password=bcrypt('secret');$u->save();$service=app(UserKnowledgeService::class);$service->rememberConfirmed($u,'direction','rtl');$service->rememberProject($u,42,'direction','ltr');$snapshot=$service->snapshotForProject($u,42);$this->assertSame(['value'=>'ltr'],$snapshot['direction']);}
}