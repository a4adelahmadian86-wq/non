<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Services\VoiceProviderRouter;
use App\Services\AI\AiQuotaService;
use App\Services\CommerceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalAssistantVoiceTest extends TestCase
{
    use RefreshDatabase;

    private function wavSample(): string
    {
        return 'RIFF'.pack('V', 38).'WAVEfmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 8000).pack('V', 16000).pack('v', 2).pack('v', 16).'data'.pack('V', 2).pack('v', 0);
    }

    public function test_global_assistant_can_issue_a_scoped_live_voice_token_outside_the_editor(): void
    {
        config(['services.voice_stream.url' => 'wss://voice.example.test']);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/assistant/voice/stream-token', ['locale' => 'fa-IR']);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('websocket_url', 'wss://voice.example.test');
        $this->assertNotEmpty($response->json('token'));
        $appKey = (string) config('app.key');
        if ($appKey !== '') {
            $this->assertStringNotContainsString($appKey, $response->getContent());
        }
    }

    public function test_live_gateway_takes_priority_over_configured_batch_asr(): void
    {
        config([
            'services.voice_stream.url' => 'wss://voice.example.test',
            'services.voice_stream.mode' => 'router',
            'services.voice_stream.gateway_secret' => 'gateway-secret',
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-token',
        ]);

        $router = \Mockery::mock(VoiceProviderRouter::class);
        $account = new VoiceProviderAccount();
        $account->setAttribute('id', 123);
        $router->shouldReceive('best')->once()->with('fa-IR')->andReturn($account);
        $this->app->instance(VoiceProviderRouter::class, $router);

        $response = $this->actingAs(User::factory()->create())->getJson('/editor/voice/status');

        $response->assertOk()
            ->assertJsonPath('mode', 'gateway')
            ->assertJsonPath('gateway', true)
            ->assertJsonPath('chunked', false)
            ->assertJsonPath('websocket_url', 'wss://voice.example.test');
        $this->assertStringNotContainsString('gateway-secret', $response->getContent());
        $this->assertStringNotContainsString('asr-token', $response->getContent());
    }

    public function test_assistant_transcription_uses_self_hosted_service_with_metered_request(): void
    {
        config([
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-test-token',
        ]);
        Http::fake([
            'https://voice.test/v1/transcribe' => Http::response(['text' => 'سلام فراست'], 200),
        ]);
        $quota = \\Mockery::mock(AiQuotaService::class);
        $quota->shouldReceive('assertAllowed')->once();
        $this->app->instance(AiQuotaService::class, $quota);
        $commerce = \\Mockery::mock(CommerceAuthorizationService::class);
        $commerce->shouldReceive('reserve')->once()->andReturn('voice-reservation');
        $commerce->shouldReceive('commit')->once()->with('voice-reservation', \\Mockery::type('array'));
        $this->app->instance(CommerceAuthorizationService::class, $commerce);

        $response = $this->actingAs(User::factory()->create())
            ->post('/assistant/transcribe', [
                'audio' => UploadedFile::fake()->createWithContent('voice.wav', $this->wavSample()),
                'locale' => 'fa-IR',
            ]);
        $this->assertSame(200, $response->status(), 'ASR proxy response: '.json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $response->assertJsonPath('text', 'سلام فراست');

        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://voice.test/v1/transcribe'
            && $request->hasHeader('Authorization', 'Bearer asr-test-token')
        );
    }

    public function test_assistant_synthesis_returns_the_upstream_audio_binary_with_its_content_type(): void
    {
        config([
            'services.voice_tts.url' => 'https://voice.test/v1/synthesize',
            'services.voice_tts.token' => 'tts-test-token',
            'services.voice_tts.voice_id' => 'farast-fa-male-v1',
        ]);
        Http::fake([
            'https://voice.test/v1/synthesize' => Http::response('fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
                'X-Farast-Voice-Id' => 'farast-fa-male-v1',
                'X-Farast-TTS-Provider' => 'microsoft-edge-tts',
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/assistant/speak', ['text' => 'سلام، به فراست خوش آمدید.']);

        $response->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Farast-Voice-Id', 'farast-fa-male-v1')
            ->assertHeader('X-Farast-TTS-Provider', 'microsoft-edge-tts');
        $this->assertSame('fake-mp3-bytes', $response->getContent());

        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://voice.test/v1/synthesize'
            && $request->hasHeader('Authorization', 'Bearer tts-test-token')
            && ($request->data()['voice_id'] ?? null) === 'farast-fa-male-v1'
        );
    }

    public function test_assistant_synthesis_rejects_empty_audio_payloads(): void
    {
        config([
            'services.voice_tts.url' => 'https://voice.test/v1/synthesize',
            'services.voice_tts.token' => 'tts-test-token',
            'services.voice_tts.voice_id' => 'farast-fa-male-v1',
        ]);
        Http::fake([
            'https://voice.test/v1/synthesize' => Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/assistant/speak', ['text' => 'سلام']);

        $response->assertStatus(502)
            ->assertJsonPath('ok', false);
    }

    public function test_voice_status_exposes_readiness_booleans_but_never_provider_tokens(): void
    {
        config([
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-secret-never-return',
            'services.voice_tts.url' => 'https://voice.test/v1/synthesize',
            'services.voice_tts.token' => 'tts-secret-never-return',
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/assistant/voice/status');

        $response->assertOk()
            ->assertJsonPath('server_stt', true)
            ->assertJsonPath('server_tts', true)
            ->assertJsonPath('server_stt_token_configured', true)
            ->assertJsonPath('server_tts_token_configured', true);
        $this->assertStringNotContainsString('asr-secret-never-return', $response->getContent());
        $this->assertStringNotContainsString('tts-secret-never-return', $response->getContent());
    }
}
