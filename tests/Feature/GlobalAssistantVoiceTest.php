<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalAssistantVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_transcription_proxies_bearer_auth_to_the_configured_free_voice_service(): void
    {
        config([
            'services.voice_asr.url' => 'https://voice.test/v1/transcribe',
            'services.voice_asr.token' => 'asr-test-token',
        ]);
        Http::fake([
            'https://voice.test/v1/transcribe' => Http::response(['text' => 'سلام فراست'], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/assistant/transcribe', [
                'audio' => UploadedFile::fake()->create('voice.wav', 10, 'audio/wav'),
                'locale' => 'fa-IR',
            ])
            ->assertOk()
            ->assertJsonPath('text', 'سلام فراست');

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
            'https://voice.test/v1/synthesize' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/assistant/speak', ['text' => 'سلام، به فراست خوش آمدید.']);

        $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertSame('fake-mp3-bytes', $response->getContent());

        Http::assertSent(fn (ClientRequest $request) =>
            $request->url() === 'https://voice.test/v1/synthesize'
            && $request->hasHeader('Authorization', 'Bearer tts-test-token')
            && ($request->data()['voice_id'] ?? null) === 'farast-fa-male-v1'
        );
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
