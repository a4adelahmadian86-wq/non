<?php

namespace Tests\Feature;

use App\Http\Controllers\GlobalAssistantController;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalAssistantGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_only_status_accepts_the_private_domestic_gateway_without_an_online_primary(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.voice.remote_batch_enabled' => false,
            'services.ai_gateway.url' => '',
            'services.ai_gateway.token' => '',
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.internal',
            'services.ai_gateway.fallback_token' => 'domestic-token',
            'services.ai_gateway.health_url' => '',
            'services.voice_tts.url' => '',
            'services.voice_tts.token' => '',
        ]);

        Http::fake([
            'https://domestic-gateway.internal/ready' => Http::response([
                'ok' => true,
                'model' => 'qwen-local',
                'provider' => 'ollama',
            ], 200),
        ]);

        $response = app(GlobalAssistantController::class)
            ->voiceStatus(Request::create('/assistant/voice/status', 'GET'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertTrue($payload['local_only']);
        $this->assertTrue($payload['chat_gateway_ready']);
        $this->assertSame('https://domestic-gateway.internal/ready', $payload['chat_gateway_health_url']);

        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->method() === 'GET'
            && $request->url() === 'https://domestic-gateway.internal/ready'
        );
    }

    public function test_global_assistant_prefers_central_gateway_before_direct_groq(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);
        SiteSetting::write('voice_groq_key', 'groq-test-key', true);
        $user = User::factory()->create();

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-assistant-test',
                'model' => 'gemini-3.8-flash',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'سلام، من همراه فراست هستم.'],
                ]],
            ], 200, ['X-Farast-AI-Provider' => 'gemini']),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'farast-ai-gateway')
            ->assertJsonPath('reply', 'سلام، من همراه فراست هستم.');

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === 'https://gateway.example/v1/chat/completions'
                && $request->header('Authorization') === ['Bearer gateway-test-token']
                && ($body['model'] ?? null) === 'farast-general'
                && count($body['messages'] ?? []) >= 2;
        });
    }

    public function test_global_assistant_uses_cloudflare_ai_gateway_without_payload_logging(): void
    {
        config([
            'services.ai_gateway.url' => '',
            'services.ai_gateway.token' => '',
            'services.ai_gateway.fallback_url' => 'https://api.cloudflare.com/client/v4/accounts/account-test/ai',
            'services.ai_gateway.fallback_token' => 'cloudflare-gateway-token',
            'services.ai_gateway.fallback_model' => '@cf/openai/gpt-oss-20b',
            'services.ai_gateway.fallback_gateway_id' => 'default',
        ]);

        $user = User::factory()->create();
        $url = 'https://api.cloudflare.com/client/v4/accounts/account-test/ai/v1/chat/completions';
        Http::fake([
            $url => Http::response([
                'id' => 'chatcmpl-cloudflare-test',
                'model' => '@cf/openai/gpt-oss-20b',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'پاسخ از Workers AI'],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'پاسخ از Workers AI');

        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->url() === $url
            && $request->header('Authorization') === ['Bearer cloudflare-gateway-token']
            && $request->header('cf-aig-gateway-id') === ['default']
            && $request->header('cf-aig-collect-log') === ['false']
            && $request->header('cf-aig-collect-log-payload') === ['false']
            && ($request->data()['model'] ?? null) === '@cf/openai/gpt-oss-20b'
        );
    }

    public function test_global_assistant_falls_back_to_domestic_gateway_when_primary_gateway_is_unavailable(): void
    {
        config([
            'services.ai_gateway.url' => 'https://online-gateway.example',
            'services.ai_gateway.token' => 'online-token',
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.example',
            'services.ai_gateway.fallback_token' => 'domestic-token',
            'services.ai_gateway.fallback_model' => 'farast-general',
        ]);

        $user = User::factory()->create();

        Http::fake([
            'https://online-gateway.example/v1/chat/completions' => Http::response(['error' => 'unavailable'], 503),
            'https://domestic-gateway.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-domestic-fallback',
                'model' => 'farast-general',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'پاسخ از درگاه داخلی'],
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'farast-ai-gateway')
            ->assertJsonPath('reply', 'پاسخ از درگاه داخلی');

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->url() === 'https://domestic-gateway.example/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer domestic-token']
        );
    }

}
