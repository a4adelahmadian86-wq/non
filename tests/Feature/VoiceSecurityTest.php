<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VoiceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_token_never_contains_provider_credentials(): void
    {
        $user=User::factory()->create();
        $response=$this->actingAs($user)->postJson('/editor/voice/stream-token',['locale'=>'fa-IR']);
        $response->assertOk()->assertJsonMissingPath('credentials')->assertJsonStructure(['session_id','token','expires_at','websocket_url']);
    }

    public function test_provider_credentials_are_gateway_only(): void
    {
        Config::set('services.voice_stream.gateway_secret','test-gateway-secret');
        $user=User::factory()->create();
        $account=new VoiceProviderAccount(['provider'=>'google-cloud-speech','name'=>'test','model'=>'v1','enabled'=>true,'healthy'=>true,'priority'=>1,'quality_score'=>90,'reliability_score'=>90,'capabilities'=>['locales'=>['fa-IR']]]);
        $account->credentials_array=['api_key'=>'super-secret-test-key'];
        $account->save();
        $token=$this->actingAs($user)->postJson('/editor/voice/stream-token',['locale'=>'fa-IR'])->json('token');
        $this->postJson('/editor/voice/provider-config',['token'=>$token])->assertForbidden();
        $gateway=hash_hmac('sha256',$token,(string)config('services.voice_stream.gateway_secret'));
        $this->withHeaders(['X-Farast-Voice-Gateway'=>$gateway,'X-Farast-Voice-Secret'=>$gateway])->postJson('/editor/voice/provider-config',['token'=>$token])->assertOk()->assertJsonPath('credentials.api_key','super-secret-test-key');
    }
}
