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

    public function test_product_owner_assistant_receives_full_document_knowledge_even_for_a_draft(): void
    {
        config([
            'services.ai_gateway.url' => 'https://gateway.example',
            'services.ai_gateway.token' => 'gateway-test-token',
        ]);
        $user = User::factory()->create();
        $category = StoreCategory::create(['name' => 'آموزش', 'slug' => 'education', 'is_active' => true]);
        $product = StoreProduct::create([
            'seller_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'دوره نمونه',
            'slug' => 'draft-course-knowledge',
            'sku' => 'DRAFT-KNOWLEDGE',
            'type' => 'digital_file',
            'price_rials' => 1000,
            'status' => 'draft',
            'version' => '1.0',
        ]);

        $knowledge = [
            'knowledge_status' => 'available',
            'access_scope' => 'full_entitled',
            'source_count' => 1,
            'sources' => [['source_file_id' => 7, 'source_sha256' => str_repeat('a', 64)]],
            'chunks' => [['content' => 'FACT_FROM_ORIGINAL_FILE_73', 'locator' => ['page' => 2]]],
            'facts' => [[
                'key' => 'duration',
                'value' => '۷ ساعت',
                'evidence' => 'مدت دوره ۷ ساعت است.',
                'locator' => ['page' => 2],
                'verification_status' => 'evidence_backed',
            ]],
            'warnings' => [],
        ];
        $service = \Mockery::mock(ProductKnowledgeService::class);
        $service->shouldReceive('contextForProduct')
            ->once()
            ->with(\Mockery::on(fn ($candidate) => $candidate->is($product)), 12000, false)
            ->andReturn($knowledge);
        $this->app->instance(ProductKnowledgeService::class, $service);

        Http::fake([
            'https://gateway.example/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-product-knowledge',
                'model' => 'gemini-3.8-flash',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'مدت دوره طبق فایل ۷ ساعت است.'],
                ]],
            ], 200, ['X-Farast-AI-Provider' => 'gemini']),
        ]);

        $this->actingAs($user)->postJson('/assistant/chat', [
            'message' => 'مدت دوره چقدر است؟',
            'product_id' => $product->id,
        ])->assertOk()->assertJsonPath('reply', 'مدت دوره طبق فایل ۷ ساعت است.');

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $messages = $body['messages'] ?? [];
            $userMessage = (string) ($messages[1]['content'] ?? '');

            return str_contains($userMessage, 'FACT_FROM_ORIGINAL_FILE_73')
                && str_contains($userMessage, 'full_entitled')
                && str_contains($userMessage, 'evidence_backed');
        });
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
}
