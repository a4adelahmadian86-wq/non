<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FarastToolHandlerRegistry;
use App\Services\SpeechToolHandler;
use App\Services\VoiceTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safe-import regression for FARAST voice.
 * Verifies auth boundaries, tool registry mapping, and that the
 * client insert path is Kernel-bound (static source contract).
 */
class VoiceSafeImportTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Voice Import',
            'mobile' => '09'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);
    }

    public function test_guest_cannot_request_stream_token_or_transcribe(): void
    {
        $this->postJson('/editor/voice/stream-token', ['locale' => 'fa-IR'])->assertUnauthorized();
        $this->postJson('/editor/voice/transcribe', ['locale' => 'fa-IR'])->assertUnauthorized();
    }

    public function test_stream_config_rejects_missing_token_and_gateway_header(): void
    {
        $this->postJson('/editor/voice/stream-config', [])->assertStatus(401);
        $this->postJson('/editor/voice/stream-config', ['token' => 'not-a-valid-token'])->assertStatus(401);
    }

    public function test_speech_transcription_is_registered_once_in_tool_handler_registry(): void
    {
        $registry = app(FarastToolHandlerRegistry::class);
        $handler = $registry->handler('speech.transcription');
        $this->assertInstanceOf(SpeechToolHandler::class, $handler);
    }

    public function test_speech_tool_handler_delegates_to_existing_transcription_service(): void
    {
        $this->mock(VoiceTranscriptionService::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->once()
                ->withArgs(function (string $mime, string $bytes, string $locale, array $context) {
                    return $mime === 'audio/wav'
                        && $bytes === 'abc'
                        && $locale === 'fa-IR'
                        && ($context['processing_mode'] ?? null) === 'shared_tool';
                })
                ->andReturn(['text' => 'سلام', 'engine' => 'mock']);
        });

        $result = app(SpeechToolHandler::class)->handle($this->user(), [
            'mime' => 'audio/wav',
            'bytes_base64' => base64_encode('abc'),
            'locale' => 'fa-IR',
        ]);

        $this->assertSame('سلام', $result['text']);
        $this->assertSame('mock', $result['engine']);
    }

    public function test_client_final_transcript_path_is_kernel_bound_not_dom_text_node(): void
    {
        $js = file_get_contents(base_path('public/js/farast-voice.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString("kernel.execute('InsertText'", $js);
        $this->assertStringContainsString('FarastVoiceRuntime', $js);
        $this->assertStringNotContainsString('createTextNode', $js);
        $this->assertStringNotContainsString('function ensureInterim', $js);
        $this->assertStringNotContainsString('function placeCaret', $js);
    }

    public function test_voice_stream_env_example_has_empty_credentials_only(): void
    {
        $path = base_path('voice-stream/.env.example');
        $this->assertFileExists($path);
        $body = file_get_contents($path);
        $this->assertStringContainsString('GOOGLE_APPLICATION_CREDENTIALS=', $body);
        $this->assertStringContainsString('GOOGLE_CLOUD_PROJECT=', $body);
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            if (in_array($key, ['GOOGLE_APPLICATION_CREDENTIALS', 'GOOGLE_CLOUD_PROJECT'], true)) {
                $this->assertSame('', trim($value), "credential key {$key} must stay empty in .env.example");
            }
            $this->assertDoesNotMatchRegularExpression('/(AIza|sk-|BEGIN PRIVATE KEY)/i', $line);
        }
    }

    public function test_editor_surface_loads_voice_assets_and_mic_attachment(): void
    {
        $layout = file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $editor = file_get_contents(base_path('resources/views/editor.blade.php'));
        $core = file_get_contents(base_path('public/js/editor-core.js'));

        $this->assertStringContainsString('/css/voice.css', $layout);
        $this->assertStringContainsString('/js/farast-voice.js', $layout);
        $this->assertStringContainsString('id="mic"', $editor);
        $this->assertStringContainsString("bind('voice'", $core);
        $this->assertStringContainsString('FarastVoiceRuntime', $core);
    }
}
