<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
class PlatformCompletionTest extends TestCase {
 use RefreshDatabase;
 public function test_authenticated_user_can_open_document_workspace(): void {
  $u=new User; $u->name='QA'; $u->email='qa-'.uniqid().'@example.test'; $u->password=bcrypt('secret'); $u->mobile='09'.str_pad((string)random_int(100000000,999999999),9,'0'); $u->save();
  $this->actingAs($u)->get('/workspace/documents')->assertOk();
 }
 public function test_authenticated_user_can_create_document(): void {
  $u=new User; $u->name='QA'; $u->email='qa-'.uniqid().'@example.test'; $u->password=bcrypt('secret'); $u->save();
  $this->actingAs($u)->post('/workspace/documents',['title'=>'تست','content'=>'سلام'])->assertRedirect();
  $this->assertDatabaseHas('farast_documents',['user_id'=>$u->id,'title'=>'تست']);
  $this->assertDatabaseHas('farast_document_versions',['user_id'=>$u->id]);
 }
}
