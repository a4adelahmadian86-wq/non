<?php
namespace Tests\Unit;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class AuthorizationServiceTest extends TestCase {
 use RefreshDatabase;
 public function test_role_permission_is_server_side():void{
  app(AuthorizationService::class)->syncDefaults();
  $u=User::factory()->create(['role'=>User::ROLE_EMPLOYEE,'is_blocked'=>false]);
  $this->assertTrue(app(AuthorizationService::class)->allows($u,'documents.edit'));
  $this->assertFalse(app(AuthorizationService::class)->allows($u,'users.delete'));
  $u->is_blocked=true;$u->save();$this->assertFalse(app(AuthorizationService::class)->allows($u,'documents.edit'));
 }
}