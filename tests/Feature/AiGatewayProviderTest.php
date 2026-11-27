<?php

namespace Tests\Feature;

use App\Services\AI\Providers\GeminiEditorProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AiGatewayProviderTest extends TestCase
{
    public function test_local_only_editor_ai_never_falls_through_to_direct_gemini_when_domestic_gateway_fails(): void
    {
        config([
            'services.voice.local_only' => true,
            'services.ai_gateway.url' => '',
            'services.ai_gateway.token' => '',
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.internal',
            'services.ai_gateway.fallback_token' => 'domestic-token',
            'services.ai_gateway.fallback_model' => 'qwen-local',
            'services.gemini.key' => 'must-not-be-used',
        ]);

        Http::fake([
            'https://domestic-gateway.internal/v1/chat/completions' => Http::response(['error' => 'offline'], 503),
        ]);

        try {
            app(GeminiEditorProvider::class)->execute(
                ['name' => 'selection.shorten', 'result' => 'text'],
                ['text' => 'متن محرمانه', 'context' => []],
                'local-only-failure-test',
            );
            $this->fail('Expected the local gateway outage to stop processing without cloud fallback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_gateway_unavailable', $exception->getMessage());
        }

        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->url() === 'https://domestic-gateway.internal/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer domestic-token')
        );
        Http::assertNotSent(fn (ClientRequest $request): bool =>
            str_contains($request->url(), 'generativelanguage.googleapis.com')
        );
    }

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

    public function test_editor_ai_uses_cloudflare_gateway_with_logging_disabled(): void
    {
        config([
            'services.ai_gateway.url' => 'https://online-gateway.example',
            'services.ai_gateway.token' => 'online-token',
            'services.ai_gateway.fallback_url' => 'https://api.cloudflare.com/client/v4/accounts/account-test/ai',
            'services.ai_gateway.fallback_token' => 'cloudflare-token',
            'services.ai_gateway.fallback_model' => '@cf/openai/gpt-oss-20b',
            'services.ai_gateway.fallback_gateway_id' => 'default',
        ]);

        $cloudflareUrl = 'https://api.cloudflare.com/client/v4/accounts/account-test/ai/v1/chat/completions';
        Http::fake([
            'https://online-gateway.example/v1/chat/completions' => Http::response(['error' => 'unavailable'], 503),
            $cloudflareUrl => Http::response([
                'id' => 'chatcmpl-cloudflare-editor',
                'model' => '@cf/openai/gpt-oss-20b',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"text":"نسخه ویرایش‌شده"}'],
                ]],
                'usage' => ['prompt_tokens' => 25, 'completion_tokens' => 9, 'total_tokens' => 34],
            ], 200),
        ]);

        $result = app(GeminiEditorProvider::class)->execute(
            ['name' => 'selection.shorten', 'result' => 'text'],
            ['text' => 'متن آزمایشی برای ویرایش.', 'context' => []],
            'cloudflare-editor-request',
        );

        $this->assertSame(['text' => 'نسخه ویرایش‌شده'], $result['result']);
        $this->assertTrue($result['fallback_used']);
        $this->assertSame('@cf/openai/gpt-oss-20b', $result['model']);

        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->url() === $cloudflareUrl
            && $request->header('Authorization') === ['Bearer cloudflare-token']
            && $request->header('cf-aig-gateway-id') === ['default']
            && $request->header('cf-aig-collect-log') === ['false']
            && $request->header('cf-aig-collect-log-payload') === ['false']
        );
    }

    public function test_editor_ai_uses_domestic_gateway_after_primary_gateway_failure(): void
    {
        config([
            'services.ai_gateway.url' => 'https://online-gateway.example',
            'services.ai_gateway.token' => 'online-token',
            'services.ai_gateway.fallback_url' => 'https://domestic-gateway.example',
            'services.ai_gateway.fallback_token' => 'domestic-token',
            'services.ai_gateway.fallback_model' => 'farast-general',
        ]);

        Http::fake([
            'https://online-gateway.example/v1/chat/completions' => Http::response(['error' => 'unavailable'], 503),
            'https://domestic-gateway.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-editor-fallback',
                'model' => 'farast-general',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"text":"نسخه کوتاه‌شده"}'],
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
            ], 200, ['X-Farast-AI-Provider' => 'ollama-local']),
        ]);

        $provider = app(GeminiEditorProvider::class);
        $result = $provider->execute(
            ['name' => 'selection.shorten', 'result' => 'text'],
            ['text' => 'این یک متن طولانی برای آزمایش مسیر پشتیبان است.', 'context' => []],
            'editor-fallback-request',
        );

        $this->assertSame(['text' => 'نسخه کوتاه‌شده'], $result['result']);
        $this->assertTrue($result['fallback_used']);

        Http::assertSent(fn (ClientRequest $request): bool =>
            $request->url() === 'https://domestic-gateway.example/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer domestic-token']
        );
    }

}
