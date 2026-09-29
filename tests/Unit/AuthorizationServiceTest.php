<?php
namespace Tests\Unit;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AuthorizationServiceTest extends TestCase {
 use RefreshDatabase;
 public function test_role_permission_is_server_side():void{
  app(AuthorizationService::class)->syncDefaults();
  $u=new User(['mobile'=>'09120000001','name'=>'Test','role'=>User::ROLE_EMPLOYEE,'is_verified'=>true,'is_blocked'=>false]);$u->save();
  $this->assertTrue(app(AuthorizationService::class)->allows($u,'documents.edit'));
  $this->assertFalse(app(AuthorizationService::class)->allows($u,'users.delete'));
  $u->is_blocked=true;$u->save();$this->assertFalse(app(AuthorizationService::class)->allows($u,'documents.edit'));
 }
}