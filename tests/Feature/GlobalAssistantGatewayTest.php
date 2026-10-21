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
}
