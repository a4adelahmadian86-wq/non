<?php

namespace Tests\\Unit;

use App\\Services\\AI\\Providers\\GeminiEditorProvider;
use Illuminate\\Support\\Facades\\Http;
use RuntimeException;
use Tests\\TestCase;

class GeminiEditorProviderRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai_gateway.url' => 'https://farast-ai-gateway.example.workers.dev',
            'services.ai_gateway.token' => 'site-to-gateway-secret',
            'services.ai_gateway.model' => 'farast-general',
            'services.ai_gateway.fallback_url' => '',
            'services.ai_gateway.fallback_token' => '',
            'services.ai_gateway.fallback_model' => 'farast-general',
            'services.voice.local_only' => false,
        ]);
    }

    public function test_editor_ai_uses_the_farast_gateway_and_never_calls_gemini_directly(): void
    {
        Http::fake([
            'https://farast-ai-gateway.example.workers.dev/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl_gateway_123',
                'model' => '@cf/openai/gpt-oss-20b',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"text":"سلام دنیا"}',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4, 'total_tokens' => 16],
            ], 200),
        ]);

        $result = (new GeminiEditorProvider())->execute([
            'name' => 'selection.rewrite',
            'result' => 'text',
        ], [
            'text' => 'سلام',
            'context' => [],
        ], 'request-test-123');

        $this->assertSame('سلام دنیا', $result['result']['text']);
        $this->assertSame('chatcmpl_gateway_123', $result['provider_interaction_id']);
        $this->assertSame(200, $result['http_status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://farast-ai-gateway.example.workers.dev/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer site-to-gateway-secret')
                && $request->hasHeader('X-Farast-Request-Id', 'request-test-123')
                && ! $request->hasHeader('x-goog-api-key');
        });

        Http::assertNotSent(fn ($request) =>
            str_contains($request->url(), 'generativelanguage.googleapis.com')
            || str_contains($request->url(), 'api.cloudflare.com')
        );
    }

    public function test_raw_provider_api_urls_are_rejected_as_gateway_endpoints(): void
    {
        config([
            'services.ai_gateway.url' => 'https://api.cloudflare.com/client/v4/accounts/0123456789abcdef0123456789abcdef/ai',
            'services.ai_gateway.token' => 'not-a-gateway-token',
        ]);

        Http::fake();

        try {
            (new GeminiEditorProvider())->execute([
                'name' => 'selection.rewrite',
                'result' => 'text',
            ], [
                'text' => 'سلام',
                'context' => [],
            ], 'request-test-direct-provider');
            $this->fail('Expected raw provider URL to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_gateway_not_configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_editor_ai_uses_the_configured_gateway_fallback_after_primary_rate_limit(): void
    {
        config([
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.example',
            'services.ai_gateway.fallback_token' => 'domestic-gateway-secret',
        ]);

        Http::fake([
            'https://farast-ai-gateway.example.workers.dev/*' => Http::response(['error' => 'rate limited'], 429),
            'https://domestic-gateway.example/*' => Http::response([
                'id' => 'chatcmpl_fallback_123',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"text":"پاسخ جایگزین"}'],
                ]],
            ], 200),
        ]);

        $result = (new GeminiEditorProvider())->execute([
            'name' => 'selection.rewrite',
            'result' => 'text',
        ], [
            'text' => 'سلام',
            'context' => [],
        ], 'request-test-fallback');

        $this->assertSame('پاسخ جایگزین', $result['result']['text']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://domestic-gateway.example/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer domestic-gateway-secret')
        );
    }

    public function test_local_only_mode_rejects_public_gateways_without_direct_provider_fallback(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.ai_gateway.url' => 'https://farast-ai-gateway.example.workers.dev',
            'services.ai_gateway.token' => 'site-to-gateway-secret',
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.example',
            'services.ai_gateway.fallback_token' => 'domestic-gateway-secret',
        ]);

        Http::fake();

        try {
            (new GeminiEditorProvider())->execute([
                'name' => 'selection.rewrite',
                'result' => 'text',
            ], [
                'text' => 'سلام',
                'context' => [],
            ], 'request-test-local-only');
            $this->fail('Expected public gateways to be rejected in local-only mode.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_gateway_not_configured', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
