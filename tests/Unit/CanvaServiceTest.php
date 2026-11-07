<?php
namespace Tests\Unit;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CanvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class CanvaServiceTest extends TestCase {
 use RefreshDatabase;
 public function test_oauth_url_uses_pkce_and_server_configuration():void{
  SiteSetting::write('canva_client_id','client-123');SiteSetting::write('canva_client_secret','secret-456',true);SiteSetting::write('canva_redirect_uri','https://example.test/canva/callback');
  $u=new User(['mobile'=>'09120000002','name'=>'Canva Test','role'=>User::ROLE_EMPLOYEE,'is_verified'=>true,'is_blocked'=>false]);$u->save();
  $data=app(CanvaService::class)->authorizeUrl($u);
  $this->assertStringStartsWith('https://www.canva.com/api/oauth/authorize?',$data['url']);$this->assertStringContainsString('code_challenge_method=s256',$data['url']);$this->assertStringContainsString('client_id=client-123',$data['url']);$this->assertNotEmpty(session('canva_oauth.verifier'));
 }
 public function test_oauth_callback_rejects_a_success_response_without_an_access_token():void{
  SiteSetting::write('canva_client_id','client-123');
  SiteSetting::write('canva_client_secret','secret-456',true);
  SiteSetting::write('canva_redirect_uri','https://example.test/canva/callback');
  $user=new User(['mobile'=>'09120000003','name'=>'Canva Invalid Token Test','role'=>User::ROLE_EMPLOYEE,'is_verified'=>true,'is_blocked'=>false]);$user->save();
  session(['canva_oauth'=>['state'=>'state-123','verifier'=>'verifier-123']]);
  \Illuminate\Support\Facades\Http::fake(['https://api.canva.com/rest/v1/oauth/token'=>\Illuminate\Support\Facades\Http::response(['refresh_token'=>'refresh-only','expires_in'=>3600],200)]);
  try {
   app(CanvaService::class)->callback($user,'authorization-code','state-123');
   $this->fail('An incomplete token response must not create a connected account.');
  } catch (\RuntimeException $exception) {
   $this->assertSame('canva_token_response_invalid',$exception->getMessage());
  }
  $this->assertDatabaseMissing('canva_connections',['user_id'=>$user->id]);
 }

 public function test_oauth_callback_persists_a_valid_token_response():void{
  SiteSetting::write('canva_client_id','client-123');
  SiteSetting::write('canva_client_secret','secret-456',true);
  SiteSetting::write('canva_redirect_uri','https://example.test/canva/callback');
  $user=new User(['mobile'=>'09120000004','name'=>'Canva Valid Token Test','role'=>User::ROLE_EMPLOYEE,'is_verified'=>true,'is_blocked'=>false]);$user->save();
  session(['canva_oauth'=>['state'=>'state-456','verifier'=>'verifier-456']]);
  \Illuminate\Support\Facades\Http::fake(['https://api.canva.com/rest/v1/oauth/token'=>\Illuminate\Support\Facades\Http::response(['access_token'=>'access-123','refresh_token'=>'refresh-123','expires_in'=>3600,'scope'=>'design:meta:read'],200)]);
  $connection=app(CanvaService::class)->callback($user,'authorization-code','state-456');
  $this->assertSame('access-123',$connection->access_token);
  $this->assertSame('connected',$connection->status);
  $this->assertNotNull($connection->expires_at);
 }

}