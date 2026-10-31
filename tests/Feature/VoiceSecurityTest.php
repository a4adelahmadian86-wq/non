<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Models\FarastUsageReservation;
use App\Models\FarastUsageEvent;
use App\Services\CommerceAuthorizationService;
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

    public function test_live_gateway_requires_explicit_url_and_uses_router_provider(): void
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
            ->assertJsonPath('mode', 'gateway')
            ->assertJsonPath('gateway', true)
            ->assertJsonPath('websocket_url', 'wss://voice.example.test')
            ->assertJsonMissingPath('credentials')
            ->assertDontSee('never-return-this');
    }


    public function test_self_hosted_live_gateway_does_not_require_a_cloud_provider_account(): void
    {
        Config::set('services.voice_stream.mode', 'self_hosted');
        Config::set('services.voice_stream.url', 'ws://127.0.0.1:8000');
        Config::set('services.voice_stream.gateway_secret', 'test-self-hosted-gateway-secret');
        Config::set('services.voice_asr.url', '');
        Config::set('services.voice_asr.token', '');

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/editor/voice/status')
            ->assertOk()
            ->assertJsonPath('mode', 'gateway')
            ->assertJsonPath('gateway', true)
            ->assertJsonPath('websocket_url', 'ws://127.0.0.1:8000');
    }


    public function test_self_hosted_stream_config_reserves_transcription_usage_before_audio(): void
    {
        Config::set('services.voice_stream.mode', 'self_hosted');
        Config::set('services.voice_stream.url', 'ws://127.0.0.1:8010');
        Config::set('services.voice_stream.gateway_secret', 'local-stream-secret');
        $user = User::factory()->create();
        $token = $this->actingAs($user)->postJson('/editor/voice/stream-token', ['locale' => 'fa-IR'])->json('token');

        $reservation = new FarastUsageReservation(['reservation_id' => 'reservation-live-test']);
        $commerce = \Mockery::mock(CommerceAuthorizationService::class);
        $commerce->shouldReceive('reserve')->once()->andReturn($reservation);
        $this->app->instance(CommerceAuthorizationService::class, $commerce);

        $signature = hash_hmac('sha256', $token, 'local-stream-secret');
        $this->postJson('/editor/voice/stream-config', ['token' => $token], ['X-Farast-Voice-Gateway' => $signature])
            ->assertOk()
            ->assertJsonPath('gateway', true)
            ->assertJsonPath('reservation_id', 'reservation-live-test');
    }

    public function test_self_hosted_stream_usage_commits_the_reserved_transcription(): void
    {
        Config::set('services.voice_stream.mode', 'self_hosted');
        Config::set('services.voice_stream.gateway_secret', 'local-stream-secret');
        $user = User::factory()->create();
        FarastUsageReservation::create([
            'reservation_id' => 'reservation-live-test',
            'idempotency_key' => 'voice-live-'.$user->id.'-expired-live-session',
            'actor_id' => $user->id,
            'capability' => 'speech.transcription',
            'quantity' => 1,
            'unit' => 'request',
            'mode' => 'free',
            'status' => 'reserved',
            'expires_at' => now()->addMinutes(10),
            'metadata' => [],
        ]);
        $payload = ['uid' => $user->id, 'locale' => 'fa-IR', 'iat' => time() - 300, 'exp' => time() - 180, 'nonce' => 'expired-live-session'];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $token = $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));

        $commerce = \Mockery::mock(CommerceAuthorizationService::class);
        $commerce->shouldReceive('commit')->once()->with(
            'reservation-live-test',
            \Mockery::on(fn (array $context) => ($context['cost_metadata']['provider'] ?? null) === 'self-hosted-whisper')
        )->andReturn(new FarastUsageEvent());
        $this->app->instance(CommerceAuthorizationService::class, $commerce);

        $signature = hash_hmac('sha256', $token, 'local-stream-secret');
        $this->postJson('/editor/voice/stream-usage', [
            'token' => $token,
            'reservation_id' => 'reservation-live-test',
            'audio_seconds' => 3.2,
            'input_bytes' => 102400,
            'success' => true,
            'latency_ms' => 1800,
        ], ['X-Farast-Voice-Gateway' => $signature])->assertOk()->assertJsonPath('ok', true);
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

    public function test_self_hosted_stream_usage_cannot_settle_another_users_reservation(): void
    {
        Config::set('services.voice_stream.mode', 'self_hosted');
        Config::set('services.voice_stream.gateway_secret', 'local-stream-secret');
        $caller = User::factory()->create();
        $owner = User::factory()->create();
        FarastUsageReservation::create([
            'reservation_id' => 'reservation-owned-by-other-user',
            'idempotency_key' => 'voice-live-other-user',
            'actor_id' => $owner->id,
            'capability' => 'speech.transcription',
            'quantity' => 1,
            'unit' => 'request',
            'mode' => 'free',
            'status' => 'reserved',
            'expires_at' => now()->addMinutes(10),
            'metadata' => [],
        ]);

        $payload = ['uid' => $caller->id, 'locale' => 'fa-IR', 'iat' => time(), 'exp' => time() + 120, 'nonce' => 'caller-session'];
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $token = $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
        $signature = hash_hmac('sha256', $token, 'local-stream-secret');

        $this->postJson('/editor/voice/stream-usage', [
            'token' => $token,
            'reservation_id' => 'reservation-owned-by-other-user',
            'audio_seconds' => 3.2,
            'input_bytes' => 102400,
            'success' => true,
            'latency_ms' => 1800,
        ], ['X-Farast-Voice-Gateway' => $signature])->assertNotFound();
    }

}
