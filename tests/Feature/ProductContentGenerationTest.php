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

    public function test_ai_receives_only_stored_knowledge_report_not_raw_document_chunks(): void
    {
        $data = $this->productWithKnowledge();
        $this->mock(EditorAiAssistService::class, function ($mock) use ($data) {
            $mock->shouldReceive('assist')
                ->once()
                ->withArgs(function ($operation, $text, $context) use ($data) {
                    $this->assertSame('product.content.generate', $operation);
                    $this->assertStringContainsString('این فایل شامل راهنمای نصب', $text);
                    $this->assertStringContainsString('راهنمای نصب مرحله‌به‌مرحله', $text);
                    $this->assertStringNotContainsString('RAW_PRIVATE_CHUNK_SENTINEL', $text);
                    $this->assertStringNotContainsString('private-file-bytes', $text);
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
        $this->assertSame([$data['fact']->id], $result['suggestions']['used_fact_ids']);
        $this->assertSame('available', app(ProductContentGenerationService::class)->readiness($data['product'])['status']);
    }

    public function test_generation_rejects_a_document_whose_source_hash_is_stale(): void
    {
        $data = $this->productWithKnowledge();
        $readiness = app(ProductContentGenerationService::class)->readiness($data['product']);
        $data['file']->update(['sha256' => hash('sha256', 'replacement-file')]);

        $this->expectException(ValidationException::class);
        app(ProductContentGenerationService::class)->assertFreshProvenance(
            $data['product'],
            json_encode($readiness['source_fingerprints']),
            $readiness['source_signature']
        );
    }
}
