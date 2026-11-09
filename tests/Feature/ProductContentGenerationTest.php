<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentChunk;
use App\Models\StoreProductDocumentFact;
use App\Models\StoreProductFile;
use App\Models\User;
use App\Services\EditorAiAssistService;
use App\Services\ProductContentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductContentGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function productWithKnowledge(): array
    {
        $admin = User::create([
            'name' => 'Product Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
        $category = StoreCategory::create([
            'name' => 'دسته دانش‌محور',
            'slug' => 'evidence-products',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'slug' => 'evidence-product',
            'sku' => 'EVIDENCE-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $sourceHash = hash('sha256', 'private-file-bytes');
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/products/'.$product->id.'/private.pdf',
            'original_name' => 'private.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => $sourceHash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'test-clean',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $sourceHash,
            'processing_status' => 'completed',
            'detected_type' => 'pdf',
            'page_count' => 4,
            'word_count' => 800,
            'character_count' => 5000,
            'structure_json' => [
                'inspection' => [
                    'detected_type' => 'pdf',
                    'extracted_characters' => 5000,
                    'extraction_truncated' => false,
                    'transcription_truncated' => false,
                    'embedded_image_count' => 0,
                    'visual_inventory_complete' => true,
                    'visual_review_truncated' => false,
                ],
                'completeness' => [
                    'extraction_complete' => true,
                    'analysis_batches_complete' => true,
                    'analysis_batch_count' => 1,
                    'successful_analysis_batches' => 1,
                ],
                'ai_review' => [
                    'provider_status' => ['groq_text' => 'completed'],
                    'analysis_batch_count' => 1,
                    'successful_analysis_batches' => 1,
                    'summary' => 'این فایل شامل راهنمای نصب و استفاده از محصول است.',
                    'findings' => [],
                ],
            ],
        ]);
        $fact = StoreProductDocumentFact::create([
            'document_id' => $document->id,
            'source_sha256' => $sourceHash,
            'fact_type' => 'feature',
            'fact_key' => 'محتوای راهنما',
            'fact_value' => 'راهنمای نصب مرحله‌به‌مرحله',
            'evidence_text' => 'در صفحه نخست، مراحل نصب به‌صورت گام‌به‌گام توضیح داده شده است.',
            'locator_json' => ['page' => 1],
            'verification_status' => 'evidence_backed',
        ]);
        StoreProductDocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'chunk_type' => 'page',
            'locator_json' => ['page' => 1],
            'content_text' => 'RAW_PRIVATE_CHUNK_SENTINEL: full private source content',
            'content_sha256' => hash('sha256', 'RAW_PRIVATE_CHUNK_SENTINEL'),
            'metadata_json' => [],
        ]);

        return compact('admin', 'category', 'product', 'file', 'document', 'fact');
    }

    public function test_ai_receives_only_redacted_stored_knowledge_report_not_raw_document_chunks(): void
    {
        $data = $this->productWithKnowledge();
        $data['product']->update(['description' => 'توضیح محصول؛ ایمیل مالک owner@example.com']);
        $data['file']->update(['original_name' => 'owner@example.com-private.pdf']);
        $structure = (array) $data['document']->structure_json;
        $structure['ai_review']['summary'] .= ' تماس 09121234567';
        $structure['ai_review']['findings'] = [[
            'severity' => 'warning',
            'title' => 'کلید API sk-abcdefghijklmnopqrstuvwxyz123456',
            'detail' => 'شماره شبا DE89370400440532013000 در متن دیده شد.',
        ]];
        $structure['inspection']['embedded_image_count'] = 1;
        $structure['inspection']['visual_review_required'] = true;
        $structure['ai_review']['provider_status']['groq_vision'] = 'completed';
        $structure['ai_review']['media_reports']['visual'] = 'گزارش بینایی: بسته‌بندی آبی؛ تماس 09121234567';
        $data['document']->update(['structure_json' => $structure]);
        $data['fact']->update([
            'fact_value' => 'راهنمای نصب مرحله‌به‌مرحله؛ شبا DE89370400440532013000؛ کارت 4111111111111111',
            'evidence_text' => 'در صفحه نخست راهنما آمده است؛ کلید sk-abcdefghijklmnopqrstuvwxyz123456',
        ]);

        $this->mock(EditorAiAssistService::class, function ($mock) use ($data) {
            $mock->shouldReceive('assist')
                ->once()
                ->withArgs(function ($operation, $text, $context) use ($data) {
                    $this->assertSame('product.content.generate', $operation);
                    $this->assertStringContainsString('این فایل شامل راهنمای نصب', $text);
                    $this->assertStringContainsString('راهنمای نصب مرحله‌به‌مرحله', $text);
                    $this->assertStringContainsString('گزارش بینایی: بسته‌بندی آبی', $text);
                    $this->assertStringNotContainsString('RAW_PRIVATE_CHUNK_SENTINEL', $text);
                    $this->assertStringNotContainsString('private-file-bytes', $text);
                    $this->assertStringNotContainsString('owner@example.com', $text);
                    $this->assertStringNotContainsString('09121234567', $text);
                    $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz123456', $text);
                    $this->assertStringNotContainsString('DE89370400440532013000', $text);
                    $this->assertStringNotContainsString('4111111111111111', $text);
                    $this->assertStringContainsString('[REDACTED]', $text);
                    $this->assertSame('gemini', $context['provider']);
                    $this->assertSame('external', $context['processing_mode']);

                    return true;
                })
                ->andReturn([
                    'result' => [
                        'title' => 'راهنمای نصب محصول',
                        'short_description' => 'راهنمای مرحله‌به‌مرحله نصب محصول',
                        'description' => 'این راهنما مراحل نصب را توضیح می‌دهد.',
                        'seo_title' => 'راهنمای نصب محصول',
                        'seo_description' => 'راهنمای نصب محصول با مراحل روشن و گام‌به‌گام.',
                        'focus_keyword' => 'راهنمای نصب محصول',
                        'secondary_keywords' => ['آموزش نصب'],
                        'tags' => ['راهنما'],
                        'category_id' => (string) $data['category']->id,
                        'category_reason' => 'هم‌خوان با محتوای راهنما',
                        'selling_points' => ['آموزش گام‌به‌گام'],
                        'used_fact_ids' => [$data['fact']->id],
                        'used_source_ids' => ['file:'.$data['file']->id],
                        'unknowns' => [],
                    ],
                    'ai' => [
                        'request_id' => 'test-request-id',
                        'model' => 'gemini-test',
                    ],
                ]);
        });

        $result = app(ProductContentGenerationService::class)->generate($data['admin'], $data['product']);

        $this->assertSame('راهنمای نصب محصول', $result['suggestions']['title']);
        $this->assertSame((string) $data['category']->id, $result['suggestions']['category_id']);
        $this->assertSame([$data['fact']->id], $result['suggestions']['used_fact_ids']);
        $this->assertSame($data['fact']->id, $result['sources'][0]['facts'][0]['id']);
        $this->assertNotEmpty($result['sources'][0]['file_name']);
        $this->assertSame('available', app(ProductContentGenerationService::class)->readiness($data['product'])['status']);
    }

    public function test_generation_rejects_a_document_whose_source_hash_is_stale(): void
    {
        $data = $this->productWithKnowledge();
        $readiness = app(ProductContentGenerationService::class)->readiness($data['product']);
        $data['file']->update(['sha256' => hash('sha256', 'replacement-file')]);
        $data['product']->refresh();

        $this->expectException(ValidationException::class);
        app(ProductContentGenerationService::class)->assertFreshProvenance(
            $data['product'],
            json_encode($readiness['source_fingerprints']),
            $readiness['source_signature']
        );
    }

    public function test_generation_is_blocked_until_document_processing_is_complete(): void
    {
        $data = $this->productWithKnowledge();
        $data['document']->update(['processing_status' => 'extracted']);

        $this->mock(EditorAiAssistService::class, function ($mock) {
            $mock->shouldNotReceive('assist');
        });
        $this->expectException(ValidationException::class);

        app(ProductContentGenerationService::class)->generate($data['admin'], $data['product']);
    }


    public function test_generation_is_blocked_when_mixed_document_images_are_not_reviewed(): void
    {
        $data = $this->productWithKnowledge();
        $structure = (array) $data['document']->structure_json;
        $structure['inspection']['embedded_image_count'] = 1;
        $structure['inspection']['visual_review_required'] = true;
        $structure['ai_review']['provider_status']['groq_vision'] = 'not_configured';
        $data['document']->update(['structure_json' => $structure]);

        $this->mock(EditorAiAssistService::class, function ($mock) {
            $mock->shouldNotReceive('assist');
        });
        $this->expectException(ValidationException::class);

        app(ProductContentGenerationService::class)->generate($data['admin'], $data['product']);
    }

    public function test_generation_is_blocked_when_audio_transcription_was_truncated(): void
    {
        $data = $this->productWithKnowledge();
        $data['document']->update(['detected_type' => 'audio']);
        $structure = (array) $data['document']->structure_json;
        $structure['inspection']['detected_type'] = 'audio';
        $structure['inspection']['transcription_truncated'] = true;
        $structure['ai_review']['provider_status']['groq_text'] = 'completed';
        $data['document']->update(['structure_json' => $structure]);

        $this->mock(EditorAiAssistService::class, function ($mock) {
            $mock->shouldNotReceive('assist');
        });
        $this->expectException(ValidationException::class);

        app(ProductContentGenerationService::class)->generate($data['admin'], $data['product']);
    }

    public function test_concurrent_product_content_edit_invalidates_generated_provenance(): void
    {
        $data = $this->productWithKnowledge();
        $service = app(ProductContentGenerationService::class);
        $readiness = $service->readiness($data['product']);

        $this->assertArrayHasKey('product:content', $readiness['source_fingerprints']);
        $data['product']->update(['title' => 'عنوانی که همکار هم‌زمان تغییر داده است']);

        $this->expectException(ValidationException::class);
        $service->assertFreshProvenance(
            $data['product']->fresh(),
            json_encode($readiness['source_fingerprints']),
            $readiness['source_signature']
        );
    }

}
