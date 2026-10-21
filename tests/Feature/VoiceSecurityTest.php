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

    public function test_configured_self_hosted_asr_is_the_preferred_voice_engine(): void
    {
        Config::set('services.voice_asr.url', 'https://voice.example.test/v1/transcribe');
        Config::set('services.voice_asr.token', 'test-self-hosted-token');
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/editor/voice/status')
            ->assertOk()
            ->assertJsonPath('mode', 'chunked')
            ->assertJsonPath('self_hosted', true)
            ->assertJsonPath('gateway', false)
            ->assertJsonPath('engines.0', 'self-hosted-asr');
    }

    public function test_missing_self_hosted_token_does_not_advertise_a_fallback_engine(): void
    {
        Config::set('services.voice_asr.url', 'https://voice.example.test/v1/transcribe');
        Config::set('services.voice_asr.token', '');
        Config::set('services.gemini.key', 'test-gemini-key');
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/editor/voice/status')
            ->assertOk()
            ->assertJsonPath('mode', 'none')
            ->assertJsonPath('reason', 'self_hosted_token_missing')
            ->assertJsonPath('self_hosted', true);
    }

    public function test_legacy_websocket_provider_is_not_advertised_without_the_central_asr_gateway(): void
    {
        Config::set('services.voice_asr.url', '');
        Config::set('services.voice_asr.token', '');
        Config::set('services.voice_stream.url', 'wss://voice.example.test');
        Config::set('services.voice_stream.gateway_secret', 'test-gateway-secret');
        Config::set('services.google_speech.enabled', false);
        Config::set('services.gemini.key', '');

        $user = User::factory()->create();
        $account = new VoiceProviderAccount([
            'provider' => 'google-cloud-speech',
            'name' => 'test-live-gateway',
            'model' => 'chirp_3',
            'enabled' => true,
            'healthy' => true,
            'priority' => 1,
            'quality_score' => 90,
            'reliability_score' => 90,
            'capabilities' => ['locales' => ['fa-IR']],
        ]);
        $account->credentials_array = ['project_id' => 'test-project', 'api_key' => 'never-return-this'];
        $account->save();

        $this->actingAs($user)->getJson('/editor/voice/status')
            ->assertOk()
            ->assertJsonPath('mode', 'none')
            ->assertJsonPath('reason', 'server_not_configured')
            ->assertJsonPath('gateway', false)
            ->assertJsonPath('websocket_url', '')
            ->assertJsonMissingPath('credentials')
            ->assertDontSee('never-return-this');
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
