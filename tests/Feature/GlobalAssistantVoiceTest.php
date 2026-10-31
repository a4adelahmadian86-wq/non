<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceProviderAccount;
use App\Services\VoiceProviderRouter;
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

    public function test_local_live_gateway_status_identifies_server_only_persian_mode(): void
    {
        $original = getenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
        putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED=true');
        $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        config(['services.voice.local_only' => true]);
        config([
            'services.voice_stream.url' => 'wss://voice.example.test',
            'services.voice_stream.gateway_secret' => 'gateway-secret',
        ]);

        $router = \Mockery::mock(VoiceProviderRouter::class);
        $account = new VoiceProviderAccount();
        $account->setAttribute('id', 321);
        $account->setAttribute('provider', 'sherpa-onnx');
        $router->shouldReceive('best')->once()->with('fa-IR')->andReturn($account);
        $this->app->instance(VoiceProviderRouter::class, $router);

        try {
            $response = $this->actingAs(User::factory()->create())->getJson('/editor/voice/status');

            $response->assertOk()
                ->assertJsonPath('mode', 'gateway')
                ->assertJsonPath('self_hosted', true)
                ->assertJsonPath('locale', 'fa-IR');
        } finally {
            if ($original === false) {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
                unset($_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'], $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED']);
            } else {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED='.$original);
                $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
                $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
            }
        }
    }

    public function test_local_only_assistant_never_falls_back_to_groq_or_non_streaming_ai(): void
    {
        $original = getenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
        $groqOriginal = getenv('GROQ_API_KEY');
        putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED=true');
        $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        config(['services.voice.local_only' => true]);
        putenv('GROQ_API_KEY=must-not-be-used');
        $_ENV['GROQ_API_KEY'] = 'must-not-be-used';
        $_SERVER['GROQ_API_KEY'] = 'must-not-be-used';
        config(['services.ai_gateway.url' => 'https://api.groq.com', 'services.ai_gateway.token' => 'must-not-be-used']);
        Http::fake();

        try {
            $user = User::factory()->create();
            $stream = $this->actingAs($user)->postJson('/assistant/chat/stream', ['message' => 'سلام']);
            $stream->assertStatus(503)->assertJsonPath('ok', false);

            $legacy = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
            $legacy->assertStatus(503)->assertJsonPath('ok', false);

            config(['services.voice_tts.url' => 'https://voice-typing-r5r7.onrender.com/v1/synthesize']);
            $speech = $this->actingAs($user)->postJson('/assistant/speak', ['text' => 'سلام']);
            $speech->assertStatus(503)->assertJsonPath('ok', false);

            Http::assertNothingSent();
        } finally {
            if ($original === false) {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
                unset($_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'], $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED']);
            } else {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED='.$original);
                $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
                $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
            }
            if ($groqOriginal === false) {
                putenv('GROQ_API_KEY');
                unset($_ENV['GROQ_API_KEY'], $_SERVER['GROQ_API_KEY']);
            } else {
                putenv('GROQ_API_KEY='.$groqOriginal);
                $_ENV['GROQ_API_KEY'] = $groqOriginal;
                $_SERVER['GROQ_API_KEY'] = $groqOriginal;
            }
        }
    }

    public function test_local_only_assistant_does_not_fall_back_when_private_llm_gateway_fails(): void
    {
        $original = getenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
        $groqOriginal = getenv('GROQ_API_KEY');
        putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED=true');
        $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        config(['services.voice.local_only' => true]);
        putenv('GROQ_API_KEY=must-not-be-used');
        $_ENV['GROQ_API_KEY'] = 'must-not-be-used';
        $_SERVER['GROQ_API_KEY'] = 'must-not-be-used';
        config([
            'services.ai_gateway.url' => 'https://private-ai.test',
            'services.ai_gateway.token' => 'private-ai-token',
            'services.ai_gateway.model' => 'qwen3:4b',
        ]);
        Http::fake([
            'https://private-ai.test/*' => Http::response(['error' => 'offline'], 503),
            '*' => Http::response(['choices' => [['message' => ['content' => 'cloud fallback']]]], 200),
        ]);

        try {
            $response = $this->actingAs(User::factory()->create())
                ->postJson('/assistant/chat/stream', ['message' => 'سلام']);

            $response->assertStatus(503)->assertJsonPath('ok', false);
            Http::assertSentCount(1);
            Http::assertSent(fn (ClientRequest $request) =>
                $request->url() === 'https://private-ai.test/v1/chat/completions'
                && ($request->data()['model'] ?? null) === 'qwen3:4b'
            );
        } finally {
            if ($original === false) {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
                unset($_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'], $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED']);
            } else {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED='.$original);
                $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
                $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
            }
            if ($groqOriginal === false) {
                putenv('GROQ_API_KEY');
                unset($_ENV['GROQ_API_KEY'], $_SERVER['GROQ_API_KEY']);
            } else {
                putenv('GROQ_API_KEY='.$groqOriginal);
                $_ENV['GROQ_API_KEY'] = $groqOriginal;
                $_SERVER['GROQ_API_KEY'] = $groqOriginal;
            }
        }
    }

    public function test_assistant_transcription_proxies_bearer_auth_to_the_configured_free_voice_service(): void
    {
        config([
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-test-token',
        ]);
        Http::fake([
            'https://voice.test/v1/transcribe' => Http::response(['text' => 'سلام فراست'], 200),
        ]);

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

    public function test_local_only_voice_mode_disables_batch_assistant_transcription(): void
    {
        $original = getenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
        putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED=true');
        $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = 'true';
        config(['services.voice.local_only' => true]);
        Http::fake();

        try {
            $response = $this->actingAs(User::factory()->create())
                ->postJson('/assistant/transcribe', []);

            $response->assertStatus(503)
                ->assertJsonPath('ok', false);
            Http::assertNothingSent();
        } finally {
            if ($original === false) {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED');
                unset($_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'], $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED']);
            } else {
                putenv('FARAST_VOICE_LOCAL_SPEECH_ENABLED='.$original);
                $_ENV['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
                $_SERVER['FARAST_VOICE_LOCAL_SPEECH_ENABLED'] = $original;
            }
        }
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

    public function test_local_only_chat_returns_actionable_missing_gateway_configuration(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.ai_gateway.url' => '',
            'services.ai_gateway.token' => '',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/assistant/chat/stream', ['message' => 'سلام']);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'private_ai_gateway_not_configured')
            ->assertJsonFragment(['message' => 'برای پاسخ‌گویی در حالت خودمیزبان، این تنظیمات لازم است: FARAST_AI_GATEWAY_URL، FARAST_AI_GATEWAY_TOKEN. هیچ سرویس ابری فراخوانی نمی‌شود.']);
    }

    public function test_local_only_voice_status_lists_missing_stream_dependencies_without_secrets(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.voice_stream.url' => '',
            'services.voice_stream.gateway_secret' => '',
        ]);
        $router = \Mockery::mock(VoiceProviderRouter::class);
        $router->shouldReceive('best')->once()->with('fa-IR')->andReturn(null);
        $this->app->instance(VoiceProviderRouter::class, $router);

        $response = $this->actingAs(User::factory()->create())->getJson('/editor/voice/status');

        $response->assertOk()
            ->assertJsonPath('mode', 'none')
            ->assertJsonPath('missing.0', 'VOICE_STREAM_URL')
            ->assertJsonPath('missing.1', 'VOICE_GATEWAY_SECRET')
            ->assertJsonPath('missing.2', 'موتور Sherpa-ONNX فارسی فعال و سالم (اجرای seeder و بررسی ظرفیت)');
    }

    public function test_local_only_assistant_status_does_not_claim_chat_is_ready_without_a_private_llm_gateway(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.ai_gateway.url' => '',
            'services.ai_gateway.token' => '',
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/assistant/voice/status');

        $response->assertOk()
            ->assertJsonPath('local_only', true)
            ->assertJsonPath('chat_gateway_ready', false)
            ->assertJsonFragment(['chat_gateway_message' => 'برای پاسخ‌گویی در حالت خودمیزبان، این تنظیمات لازم است: FARAST_AI_GATEWAY_URL، FARAST_AI_GATEWAY_TOKEN. هیچ سرویس ابری فراخوانی نمی‌شود.']);
    }


    public function test_explicit_remote_batch_voice_opt_in_allows_render_voice_without_enabling_cloud_chat(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.voice.remote_batch_enabled' => true,
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-test-token',
            'services.voice_tts.url' => 'https://voice.test/v1/synthesize',
            'services.voice_tts.token' => 'tts-test-token',
            'services.voice_tts.voice_id' => 'farast-fa-male-v1',
        ]);
        Http::fake([
            'https://voice.test/v1/transcribe' => Http::response(['text' => 'سلام فراست'], 200),
            'https://voice.test/v1/synthesize' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $user = User::factory()->create();
        $transcription = $this->actingAs($user)->post('/assistant/transcribe', [
            'audio' => UploadedFile::fake()->createWithContent('voice.wav', $this->wavSample()),
            'locale' => 'fa-IR',
        ]);
        $transcription->assertOk()->assertJsonPath('text', 'سلام فراست');

        $speech = $this->actingAs($user)->postJson('/assistant/speak', ['text' => 'سلام']);
        $speech->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        $chat = $this->actingAs($user)->postJson('/assistant/chat/stream', ['message' => 'سلام']);
        $chat->assertStatus(503)->assertJsonPath('code', 'private_ai_gateway_not_configured');

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://voice.test/v1/transcribe'
            && $request->hasHeader('Authorization', 'Bearer asr-test-token')
        );
        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://voice.test/v1/synthesize'
            && $request->hasHeader('Authorization', 'Bearer tts-test-token')
        );
    }

}
