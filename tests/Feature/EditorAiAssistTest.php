<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditorAiAssistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.key', 'test-secret-key');
        config()->set('services.gemini.model', 'test-model');
    }

    public function test_guest_cannot_call_ai(): void
    {
        Http::fake();
        $this->postJson('/editor/ai/assist', ['operation'=>'selection.punctuation','text'=>'سلام'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_normalized_response_and_legacy_compatibility(): void
    {
        Http::fake(fn () => Http::response(['id'=>'p1','outputs'=>[['type'=>'text','text'=>json_encode(['text'=>'سلام، دنیا!'], JSON_UNESCAPED_UNICODE)]]],200));
        $response = $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'punctuation','text'=>'سلام دنیا','processing_mode'=>'external']);
        $response->assertOk()->assertJsonPath('text','سلام، دنیا!')->assertJsonPath('ai.operation','selection.punctuation')->assertJsonPath('ai.provider','gemini')->assertJsonPath('ai.processing_mode','external')->assertJsonPath('ai.model','test-model');
        $this->assertStringNotContainsString('test-secret-key',$response->getContent());
        $this->assertArrayNotHasKey('text', AiInteraction::latest('id')->firstOrFail()->input_meta);
    }

    public function test_product_editor_ai_uses_owned_file_facts_as_grounding_context(): void
    {
        $user = $this->user();
        $category = StoreCategory::create(['name' => 'آموزش', 'slug' => 'ai-editor-education', 'is_active' => true]);
        $product = StoreProduct::create([
            'seller_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'کتاب نمونه',
            'slug' => 'ai-editor-book',
            'sku' => 'AI-EDITOR-BOOK',
            'type' => 'digital_file',
            'price_rials' => 1000,
            'status' => 'draft',
            'version' => '1.0',
        ]);

        $knowledge = [
            'knowledge_status' => 'available',
            'scope' => 'full_entitled',
            'source_count' => 1,
            'sources' => [],
            'chunks' => [['content' => 'EXACT_PRODUCT_SOURCE_FACT']],
            'facts' => [['key' => 'duration', 'value' => '۷ ساعت', 'evidence' => 'مدت فایل ۷ ساعت است.', 'verification_status' => 'evidence_backed']],
            'warnings' => [],
        ];
        $service = \\Mockery::mock(ProductKnowledgeService::class);
        $service->shouldReceive('contextForProduct')
            ->once()
            ->with(\\Mockery::on(fn ($candidate) => $candidate->is($product)), 12000, false)
            ->andReturn($knowledge);
        $this->app->instance(ProductKnowledgeService::class, $service);

        Http::fake(fn () => Http::response([
            'id' => 'product-title-test',
            'outputs' => [['type' => 'text', 'text' => json_encode(['titles' => ['کتاب آموزشی دقیق']], JSON_UNESCAPED_UNICODE)]],
        ], 200));

        $this->actingAs($user)->postJson('/editor/ai/assist', [
            'operation' => 'text.title',
            'text' => 'برای کتاب عنوان دقیق پیشنهاد بده',
            'product_id' => $product->id,
            'processing_mode' => 'external',
        ])->assertOk()->assertJsonPath('titles.0', 'کتاب آموزشی دقیق');

        Http::assertSent(function ($request): bool {
            $prompt = (string) data_get($request->data(), 'input.0.text', '');
            return str_contains($prompt, 'EXACT_PRODUCT_SOURCE_FACT')
                && str_contains($prompt, '۷ ساعت')
                && str_contains($prompt, 'حدس نزن');
        });
    }

    public function test_editor_ai_cannot_read_another_sellers_product_knowledge(): void
    {
        Http::fake();
        $owner = $this->user();
        $other = $this->user();
        $category = StoreCategory::create(['name' => 'آموزش', 'slug' => 'ai-editor-other', 'is_active' => true]);
        $product = StoreProduct::create([
            'seller_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'محصول خصوصی',
            'slug' => 'private-ai-product',
            'sku' => 'PRIVATE-AI-PRODUCT',
            'type' => 'digital_file',
            'price_rials' => 1000,
            'status' => 'draft',
            'version' => '1.0',
        ]);

        $this->actingAs($other)->postJson('/editor/ai/assist', [
            'operation' => 'text.title',
            'text' => 'عنوان بده',
            'product_id' => $product->id,
            'processing_mode' => 'external',
        ])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_local_mode_never_falls_back_to_external(): void
    {
        Http::fake();
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.rewrite','text'=>'متن','processing_mode'=>'local'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_unknown_provider_is_rejected_without_fallback_and_recorded(): void
    {
        Http::fake();
        $this->actingAs($this->user())->postJson('/editor/ai/assist',[
            'operation'=>'selection.rewrite',
            'text'=>'متن خصوصی',
            'processing_mode'=>'external',
            'provider'=>'unknown-provider',
        ])->assertUnprocessable()->assertJsonPath('message','ai_provider_not_registered');
        Http::assertNothingSent();

        $interaction = AiInteraction::latest('id')->firstOrFail();
        $this->assertSame('failed', $interaction->status);
        $this->assertSame('ai_provider_not_registered', $interaction->error_message);
        $this->assertSame('unresolved', $interaction->provider);
        $this->assertSame('pending', $interaction->model);
        $this->assertStringNotContainsString('متن خصوصی', json_encode($interaction->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_quota_blocks_before_provider_call(): void
    {
        Http::fake(); $user=$this->user();
        for($i=0;$i<20;$i++) AiInteraction::create(['user_id'=>$user->id,'provider'=>'test','model'=>'test','operation'=>'test','request_id'=>'q'.$i,'status'=>'completed']);
        $this->actingAs($user)->postJson('/editor/ai/assist',['operation'=>'selection.rewrite','text'=>'متن'])->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_disabled_ai_capability_blocks_before_provider_call(): void
    {
        Http::fake();
        SiteSetting::write('can_ai', '0');
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.rewrite','text'=>'متن'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_oversized_operation_input_is_rejected_before_provider_call(): void
    {
        Http::fake();
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.rewrite','text'=>str_repeat('ا',20001)])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_failed_provider_body_and_private_text_are_not_persisted_raw(): void
    {
        Http::fake(fn()=>Http::response(['error'=>['message'=>'PRIVATE BODY']],500));
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.punctuation','text'=>'متن خصوصی'])->assertStatus(502);
        $error=(string)AiInteraction::latest('id')->firstOrFail()->error_message;
        $this->assertSame('ai_provider_http_500',$error);
        $this->assertStringNotContainsString('PRIVATE BODY',$error);
        $this->assertStringNotContainsString('متن خصوصی',$error);
    }

    public function test_malformed_provider_output_is_rejected_and_normalized(): void
    {
        Http::fake(fn()=>Http::response(['id'=>'p2','outputs'=>[['type'=>'text','text'=>json_encode(['unexpected'=>'shape'])]]],200));
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.punctuation','text'=>'سلام'])->assertStatus(502);
        $this->assertSame('ai_provider_invalid_shape', AiInteraction::latest('id')->firstOrFail()->error_message);
    }

    public function test_provider_timeout_is_normalized_without_leaking_exception_text(): void
    {
        Http::fake(fn()=>throw new ConnectionException('PRIVATE TIMEOUT DETAIL'));
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.punctuation','text'=>'سلام'])->assertStatus(502);
        $error=(string)AiInteraction::latest('id')->firstOrFail()->error_message;
        $this->assertSame('ai_provider_failure',$error);
        $this->assertStringNotContainsString('PRIVATE TIMEOUT DETAIL',$error);
    }

    public function test_unvalidated_document_id_is_not_linked_to_telemetry(): void
    {
        Http::fake(fn () => Http::response(['id'=>'p3','outputs'=>[['type'=>'text','text'=>json_encode(['text'=>'سلام'], JSON_UNESCAPED_UNICODE)]]],200));
        $this->actingAs($this->user())->postJson('/editor/ai/assist',['operation'=>'selection.punctuation','text'=>'سلام','document_id'=>999])->assertOk();
        $this->assertNull(AiInteraction::latest('id')->firstOrFail()->document_id);
    }

    private function user(): User
    {
        return User::create(['name'=>'AI Test','mobile'=>'09'.str_pad((string)random_int(1,999999999),9,'0',STR_PAD_LEFT),'role'=>'user','is_verified'=>true,'is_blocked'=>false]);
    }
}
