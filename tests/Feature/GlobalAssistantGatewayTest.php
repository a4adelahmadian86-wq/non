<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalAssistantGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_assistant_uses_central_gateway_even_if_legacy_groq_secret_exists(): void
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

    public function test_global_assistant_does_not_fall_back_to_groq_when_gateway_fails(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);
        SiteSetting::write('voice_groq_key', 'groq-test-key', true);
        $user = User::factory()->create();

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response(['error' => 'unavailable'], 503),
            'https://api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'این پاسخ نباید استفاده شود.']]],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
        $response->assertStatus(503)->assertJsonPath('ok', false);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://gateway.example/v1/chat/completions');
    }

    public function test_global_assistant_fails_closed_when_gateway_is_missing_even_if_groq_is_configured(): void
    {
        SiteSetting::write('voice_groq_key', 'groq-test-key', true);
        $user = User::factory()->create();

        Http::fake();

        $response = $this->actingAs($user)->postJson('/assistant/chat', ['message' => 'سلام']);
        $response->assertStatus(503)->assertJsonPath('ok', false);
        Http::assertNothingSent();
    }

    public function test_streaming_assistant_does_not_fall_back_to_groq_when_gateway_fails(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);
        SiteSetting::write('voice_groq_key', 'groq-test-key', true);
        $user = User::factory()->create();

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response(['error' => 'unavailable'], 503),
            'https://api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'این پاسخ نباید استفاده شود.']]],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat/stream', ['message' => 'سلام']);
        $response->assertStatus(503)->assertJsonPath('ok', false);
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://gateway.example/v1/chat/completions');
    }


    public function test_streaming_assistant_forwards_gateway_deltas_as_sse(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);
        $user = User::factory()->create();
        $sse = "data: {\\"choices\\":[{\\"delta\\":{\\"content\\":\\"سلام\\ "}}]}\\n\\ndata: [DONE]\\n\\n";

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/chat/stream', ['message' => 'سلام']);
        $response->assertOk();
        $this->assertStringContainsString('"delta":"سلام"', $response->getContent());
        $this->assertStringContainsString('"done":true', $response->getContent());
        Http::assertSentCount(1);
    }

}
