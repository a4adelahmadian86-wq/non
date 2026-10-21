<?php

namespace Tests\Feature;

use App\Services\AI\Providers\GeminiEditorProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiGatewayProviderTest extends TestCase
{
    public function test_editor_ai_can_use_the_optional_central_gateway_with_structured_output(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'model' => 'gemini-3.8-flash',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"text":"نسخه کوتاه"}'],
                ]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
            ], 200, ['X-Farast-AI-Provider' => 'gemini']),
        ]);

        $provider = app(GeminiEditorProvider::class);
        $result = $provider->execute(
            ['name' => 'selection.shorten', 'result' => 'text'],
            ['text' => 'این یک متن طولانی برای آزمایش است.', 'context' => []],
            'gateway-request-123',
        );

        $this->assertSame(['text' => 'نسخه کوتاه'], $result['result']);
        $this->assertSame('chatcmpl-test', $result['provider_interaction_id']);
        $this->assertSame('gemini-3.8-flash', $result['model']);
        $this->assertSame(['input_tokens' => 12, 'output_tokens' => 4, 'total_tokens' => 16], $result['usage']);
        $this->assertFalse($result['fallback_used']);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === 'https://gateway.example/v1/chat/completions'
                && $request->header('Authorization') === ['Bearer gateway-test-token']
                && $request->header('X-Farast-Request-Id') === ['gateway-request-123']
                && ($body['model'] ?? null) === 'farast-general'
                && ($body['response_format']['type'] ?? null) === 'json_schema'
                && count($body['messages'] ?? []) === 2;
        });
    }
}
