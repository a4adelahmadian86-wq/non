<?php

namespace Tests\Unit;

use App\Models\SiteSetting;
use App\Services\AI\Providers\GeminiEditorProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiEditorProviderRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function configureGemini(): void
    {
        SiteSetting::write('gemini_api_key', 'test-gemini-key', true);
        SiteSetting::write('gemini_model', 'gemini-3.8-flash');
    }

    public function test_gemini_provider_reads_structured_text_from_new_steps_response(): void
    {
        $this->configureGemini();

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'id' => 'int_test_123',
                'status' => 'completed',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => '{"text":"سلام دنیا"}',
                    ]],
                ]],
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
        $this->assertSame('int_test_123', $result['provider_interaction_id']);
        $this->assertSame(200, $result['http_status']);
        $this->assertNotEmpty($result['prompt_hash']);

        Http::assertSent(function ($request) {
            return $request->header('x-goog-api-key')[0] === 'test-gemini-key'
                && $request->header('X-Farast-Request-Id')[0] === 'request-test-123'
                && $request->header('Api-Revision')[0] === '2026-05-20'
                && $request['store'] === false
                && ($request['response_format']['type'] ?? null) === 'text'
                && ($request['response_format']['mime_type'] ?? null) === 'application/json';
        });
    }

    public function test_gemini_prompt_includes_product_facts_and_forbids_unsupported_claims(): void
    {
        $this->configureGemini();

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'id' => 'int_product_context',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => '{"titles":["کتاب آموزشی نمونه"]}',
                    ]],
                ]],
            ], 200),
        ]);

        (new GeminiEditorProvider())->execute([
            'name' => 'text.title',
            'result' => 'titles',
        ], [
            'text' => 'برای محصول عنوان پیشنهاد بده',
            'context' => [
                'product_reference' => ['title' => 'کتاب نمونه'],
                'document_knowledge' => [
                    'knowledge_status' => 'available',
                    'facts' => [['key' => 'duration', 'value' => '۷ ساعت', 'evidence' => 'مدت کتاب صوتی ۷ ساعت است.', 'verification_status' => 'evidence_backed']],
                    'chunks' => [['content' => 'FACT_FROM_ORIGINAL_FILE']],
                ],
            ],
        ], 'request-product-context');

        Http::assertSent(function ($request): bool {
            $prompt = (string) data_get($request->data(), 'input.0.text', '');

            return str_contains($prompt, 'FACT_FROM_ORIGINAL_FILE')
                && str_contains($prompt, '۷ ساعت')
                && str_contains($prompt, 'هیچ ویژگی')
                && str_contains($prompt, 'حدس نزن');
        });
    }

    public function test_gemini_provider_maps_auth_failure_to_stable_error_code(): void
    {
        $this->configureGemini();

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response(['error' => ['message' => 'invalid key']], 401),
        ]);

        $this->expectExceptionMessage('ai_provider_auth_failed');

        (new GeminiEditorProvider())->execute([
            'name' => 'selection.rewrite',
            'result' => 'text',
        ], [
            'text' => 'سلام',
            'context' => [],
        ], 'request-test-401');
    }
}
