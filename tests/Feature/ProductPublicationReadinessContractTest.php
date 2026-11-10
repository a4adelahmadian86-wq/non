<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use App\Services\ProductPublicationReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPublicationReadinessContractTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug = 'publication-contract'): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته قرارداد انتشار',
            'slug' => $slug.'-category',
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول قرارداد انتشار',
            'slug' => $slug,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
            'download_policy' => 'reader_only',
        ]);
    }

    private function readyDocument(StoreProduct $product, array $overrides = []): StoreProductFile
    {
        $bytes = 'immutable-reader-source-'.$product->id;
        $hash = hash('sha256', $bytes);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => '1.0',
            'disk' => 'private',
            'path' => 'store/contracts/'.$product->id.'/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => $hash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);

        $document = StoreProductDocument::create(array_merge([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => 'extracted',
            'detected_type' => 'pdf',
            'page_count' => 1,
            'structure_json' => ['reader' => ['ready' => true, 'page_count' => 1]],
        ], $overrides));

        $document->pages()->create([
            'page_number' => 1,
            'page_kind' => 'pdf_page',
            'locator_key' => 'page:1',
            'locator_json' => ['page' => 1],
            'content_text' => 'متن استخراج‌شده',
            'content_html' => '<p>متن استخراج‌شده</p>',
            'content_sha256' => hash('sha256', 'متن استخراج‌شده'),
            'has_extracted_text' => true,
        ]);

        return $file;
    }

    public function test_reader_publication_requires_the_same_clean_primary_file_and_hash_matched_document_with_pages(): void
    {
        $product = $this->product();
        $this->readyDocument($product);
        $product->load('files.document.pages');

        $this->assertTrue(app(ProductPublicationReadiness::class)->hasReadyPrimaryReader($product));
    }

    public function test_reader_publication_fails_closed_when_document_source_hash_differs_from_the_file(): void
    {
        $product = $this->product('publication-hash-mismatch');
        $this->readyDocument($product, ['source_sha256' => str_repeat('f', 64)]);
        $product->load('files.document.pages');

        $this->assertFalse(app(ProductPublicationReadiness::class)->hasReadyPrimaryReader($product));
    }

    public function test_reader_publication_requires_an_active_clean_primary_download_file(): void
    {
        $product = $this->product('publication-file-state');
        $file = $this->readyDocument($product);
        $file->update(['scan_status' => 'pending']);
        $product->load('files.document.pages');

        $this->assertFalse(app(ProductPublicationReadiness::class)->hasReadyPrimaryReader($product));

        $file->update(['scan_status' => 'clean', 'is_active' => false]);
        $product->unsetRelation('files')->load('files.document.pages');

        $this->assertFalse(app(ProductPublicationReadiness::class)->hasReadyPrimaryReader($product));
    }

    public function test_reader_publication_requires_completed_extraction_reader_marker_and_persisted_pages(): void
    {
        $product = $this->product('publication-incomplete-reader');
        $file = $this->readyDocument($product, [
            'processing_status' => 'partial',
            'structure_json' => ['reader' => ['ready' => false, 'page_count' => 0]],
        ]);
        $document = $file->document;
        $document->pages()->delete();
        $product->load('files.document.pages');

        $this->assertFalse(app(ProductPublicationReadiness::class)->hasReadyPrimaryReader($product));
    }
}
