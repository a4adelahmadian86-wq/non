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
}