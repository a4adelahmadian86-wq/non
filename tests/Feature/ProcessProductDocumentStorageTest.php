<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductDocumentAudio;
use App\Jobs\ProcessProductDocument;
use App\Http\Controllers\StoreController;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Services\ProductSupervisorClient;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessProductDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        }
        parent::tearDown();
    }

    public function test_docx_source_and_generated_reader_and_sample_pdfs_use_managed_storage(): void
    {
        Queue::fake();

        $category = StoreCategory::create([
            'name' => 'دسته پردازش سند',
            'slug' => 'document-storage-processing-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول پردازش سند',
            'slug' => 'document-storage-processing-product',
            'sku' => 'DOCUMENT-STORAGE-PROCESSING',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'download_policy' => 'reader_only',
            'version' => '1.0',
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Document artifact volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $sourceBytes = "DOCX source bytes used by the storage integration test";
        $storage = app(StorageManager::class);
        $sourceManaged = $storage->storeContents(
            $sourceBytes,
            'source.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            StorageManager::CRITICAL,
            'store-product-file',
            StoreProductFile::class,
            null,
            $product->id
        );
        $sourceFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => $sourceManaged['disk'],
            'path' => $sourceManaged['path'],
            'original_name' => 'source.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => strlen($sourceBytes),
            'sha256' => hash('sha256', $sourceBytes),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $storage->bindReference(
            (int) $sourceManaged['managed_file_id'],
            StoreProductFile::class,
            (int) $sourceFile->id,
            (int) $product->id
        );

        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $sourceFile->id,
            'source_sha256' => hash('sha256', $sourceBytes),
            'processing_status' => 'pending',
        ]);
        $oldReaderPath = 'legacy/reader-before-processing.pdf';
        $oldSamplePath = 'legacy/sample-before-processing.pdf';
        $document->artifacts()->create([
            'artifact_type' => 'reader_pdf',
            'version' => 1,
            'disk' => 'private',
            'path' => $oldReaderPath,
            'mime' => 'application/pdf',
            'size_bytes' => 64,
            'sha256' => hash('sha256', 'old-reader'),
            'metadata_json' => ['source_sha256' => hash('sha256', $sourceBytes)],
        ]);
        $document->artifacts()->create([
            'artifact_type' => 'sample_pdf',
            'version' => 1,
            'disk' => 'private',
            'path' => $oldSamplePath,
            'mime' => 'application/pdf',
            'size_bytes' => 64,
            'sha256' => hash('sha256', 'old-sample'),
            'metadata_json' => [],
        ]);
        $readerPreview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => $oldReaderPath,
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'reader_document',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $samplePreview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => $oldSamplePath,
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'sample_document',
            'access_level' => 'sample',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $readerPdfBytes = '%PDF-1.4 reader artifact generated in the test';
        $samplePdfBytes = '%PDF-1.4 sample artifact generated in the test';
        $controller = Mockery::mock(StoreController::class);
        $controller->shouldReceive('prepareDocxPreview')->once()->andReturn($readerPdfBytes);
        $this->app->instance(StoreController::class, $controller);

        $payload = [
            'review_id' => 'review-storage-test',
            'product_id' => (string) $product->id,
            'inspection' => [
                'detected_type' => 'docx',
                'page_count' => 1,
                'embedded_image_count' => 0,
                'visual_inventory_complete' => true,
                'extraction_truncated' => false,
                'warnings' => [],
            ],
            'security_gate' => ['status' => 'passed'],
            'content_chunks' => [[
                'chunk_index' => 0,
                'chunk_type' => 'paragraph',
                'locator' => ['page' => 1],
                'content_text' => 'متن صفحه اول برای آزمون ذخیره‌سازی.',
                'content_sha256' => hash('sha256', 'متن صفحه اول برای آزمون ذخیره‌سازی.'),
            ]],
            'facts' => [],
            'summary' => 'خلاصه آزمون',
            'findings' => [],
            'warnings' => [],
            'provider_status' => ['groq_text' => 'completed'],
            'media_reports' => [],
            'analysis_batch_count' => 1,
            'successful_analysis_batches' => 1,
            'analyzed_characters' => 40,
            'sample_pdf_base64' => base64_encode($samplePdfBytes),
            'sample_page_numbers' => [1],
        ];
        $supervisor = Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('configured')->once()->andReturn(true);
        $supervisor->shouldReceive('reviewFile')
            ->once()
            ->withArgs(function ($reviewStream, $reviewFilename, $metadata, $sourceStream, $sourceFilename) use ($readerPdfBytes, $sourceBytes) {
                if (! is_resource($reviewStream) || ! is_resource($sourceStream)) return false;
                if ($reviewFilename !== 'source.pdf' || $sourceFilename !== 'source.docx') return false;
                return stream_get_contents($reviewStream) === $readerPdfBytes
                    && stream_get_contents($sourceStream) === $sourceBytes;
            })
            ->andReturn($payload);
        $this->app->instance(ProductSupervisorClient::class, $supervisor);

        (new ProcessProductDocument($document->id))->handle($supervisor, $storage);

        $readerArtifact = $document->fresh()->artifacts()->where('artifact_type', 'reader_pdf')->firstOrFail();
        $sampleArtifact = $document->fresh()->artifacts()->where('artifact_type', 'sample_pdf')->firstOrFail();
        $readerManaged = StorageManagedFile::query()
            ->where('source_type', StoreProductDocumentArtifact::class)
            ->where('source_id', $readerArtifact->id)
            ->firstOrFail();
        $sampleManagedFile = StorageManagedFile::query()
            ->where('source_type', StoreProductDocumentArtifact::class)
            ->where('source_id', $sampleArtifact->id)
            ->firstOrFail();
        $readerPreview->refresh();
        $samplePreview->refresh();

        $this->assertSame('storage_volume:'.$volume->id, $readerArtifact->disk);
        $this->assertSame('storage_volume:'.$volume->id, $sampleArtifact->disk);
        $this->assertNotSame($oldReaderPath, $readerArtifact->path);
        $this->assertNotSame($oldSamplePath, $sampleArtifact->path);
        $this->assertSame($readerArtifact->path, $readerManaged->file_key);
        $this->assertSame($sampleArtifact->path, $sampleManagedFile->file_key);
        $this->assertSame(false, data_get($readerManaged->metadata, 'reference_pending'));
        $this->assertSame(false, data_get($sampleManagedFile->metadata, 'reference_pending'));
        $this->assertSame($readerArtifact->path, $readerPreview->path);
        $this->assertSame($readerArtifact->disk, $readerPreview->disk);
        $this->assertSame($sampleArtifact->path, $samplePreview->path);
        $this->assertSame($sampleArtifact->disk, $samplePreview->disk);
        $this->assertSame($readerPdfBytes, $storage->get($readerArtifact->disk, $readerArtifact->path));
        $this->assertSame($samplePdfBytes, $storage->get($sampleArtifact->disk, $sampleArtifact->path));
        Queue::assertPushed(GenerateProductDocumentAudio::class);
    }
    public function test_concurrent_docx_processing_reuses_the_first_committed_reader_pdf_and_cleans_its_staged_copy(): void
    {
        Queue::fake();

        $category = StoreCategory::create([
            'name' => 'دسته هم‌زمانی سند',
            'slug' => 'document-storage-concurrency-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول هم‌زمانی سند',
            'slug' => 'document-storage-concurrency-product',
            'sku' => 'DOCUMENT-STORAGE-CONCURRENCY',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'download_policy' => 'reader_only',
            'version' => '1.0',
        ]);
        StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Concurrent document volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $sourceBytes = 'DOCX bytes for concurrent reader artifact test';
        $storage = app(StorageManager::class);
        $sourceManaged = $storage->storeContents(
            $sourceBytes,
            'source.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            StorageManager::CRITICAL,
            'store-product-file',
            StoreProductFile::class,
            null,
            $product->id
        );
        $sourceFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => $sourceManaged['disk'],
            'path' => $sourceManaged['path'],
            'original_name' => 'source.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => strlen($sourceBytes),
            'sha256' => hash('sha256', $sourceBytes),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $storage->bindReference((int) $sourceManaged['managed_file_id'], StoreProductFile::class, (int) $sourceFile->id, (int) $product->id);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $sourceFile->id,
            'source_sha256' => $sourceFile->sha256,
            'processing_status' => 'pending',
        ]);

        $readerPdfBytes = '%PDF-1.4 reader PDF from the competing worker';
        $controller = Mockery::mock(StoreController::class);
        $controller->shouldReceive('prepareDocxPreview')->once()->andReturn($readerPdfBytes);
        $this->app->instance(StoreController::class, $controller);

        $payload = [
            'inspection' => [
                'detected_type' => 'docx',
                'page_count' => 1,
                'embedded_image_count' => 0,
                'visual_inventory_complete' => true,
                'extraction_truncated' => false,
                'warnings' => [],
            ],
            'security_gate' => ['status' => 'passed'],
            'content_chunks' => [[
                'chunk_index' => 0,
                'chunk_type' => 'paragraph',
                'locator' => ['page' => 1],
                'content_text' => 'متن صفحه برای آزمون رقابت ذخیره‌سازی.',
                'content_sha256' => hash('sha256', 'متن صفحه برای آزمون رقابت ذخیره‌سازی.'),
            ]],
            'facts' => [],
            'summary' => 'خلاصه آزمون هم‌زمانی',
            'findings' => [],
            'warnings' => [],
            'provider_status' => ['groq_text' => 'completed'],
            'media_reports' => [],
            'analysis_batch_count' => 1,
            'successful_analysis_batches' => 1,
            'analyzed_characters' => 40,
        ];

        $competingArtifact = null;
        $supervisor = Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('configured')->once()->andReturn(true);
        $supervisor->shouldReceive('reviewFile')->once()->andReturnUsing(
            function ($reviewStream) use ($storage, $document, $product, $sourceBytes, $readerPdfBytes, $payload, &$competingArtifact) {
                $this->assertSame($readerPdfBytes, stream_get_contents($reviewStream));

                // Simulate another worker committing the canonical artifact after
                // this job's initial lookup but before its publication transaction.
                $competingManaged = $storage->storeContents(
                    $readerPdfBytes,
                    'reader-v1.pdf',
                    'application/pdf',
                    StorageManager::CRITICAL,
                    'store-product-artifact',
                    StoreProductDocumentArtifact::class,
                    null,
                    (int) $product->id,
                    'private'
                );
                $competingArtifact = $document->artifacts()->updateOrCreate(
                    ['artifact_type' => 'reader_pdf', 'version' => 1],
                    [
                        'disk' => $competingManaged['disk'],
                        'path' => $competingManaged['path'],
                        'mime' => 'application/pdf',
                        'size_bytes' => strlen($readerPdfBytes),
                        'sha256' => hash('sha256', $readerPdfBytes),
                        'metadata_json' => [
                            'source_sha256' => hash('sha256', $sourceBytes),
                            'conversion_engine' => 'phpword_dompdf',
                            'generated_once' => true,
                        ],
                    ]
                );
                $storage->bindReference(
                    (int) $competingManaged['managed_file_id'],
                    StoreProductDocumentArtifact::class,
                    (int) $competingArtifact->id,
                    (int) $product->id
                );

                return $payload;
            }
        );
        $this->app->instance(ProductSupervisorClient::class, $supervisor);

        (new ProcessProductDocument($document->id))->handle($supervisor, $storage);

        $readerArtifact = $document->fresh()->artifacts()->where('artifact_type', 'reader_pdf')->firstOrFail();
        $this->assertSame($competingArtifact->path, $readerArtifact->path);
        $this->assertSame($competingArtifact->disk, $readerArtifact->disk);
        $this->assertSame($readerPdfBytes, $storage->get($readerArtifact->disk, $readerArtifact->path));

        $canonicalManagedCount = StorageManagedFile::query()
            ->where('source_type', StoreProductDocumentArtifact::class)
            ->where('source_id', $readerArtifact->id)
            ->where('status', '!=', 'deleted')
            ->count();
        $unboundDeletedCount = StorageManagedFile::query()
            ->where('source_type', StoreProductDocumentArtifact::class)
            ->whereNull('source_id')
            ->where('status', 'deleted')
            ->count();

        $this->assertSame(1, $canonicalManagedCount);
        $this->assertSame(1, $unboundDeletedCount);
    }

    public function test_provider_result_is_not_published_if_the_product_source_changes_during_review(): void
    {
        Queue::fake();
        Storage::fake('private');

        $category = StoreCategory::create([
            'name' => 'دسته تغییر منبع',
            'slug' => 'document-source-change-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول تغییر منبع',
            'slug' => 'document-source-change-product',
            'sku' => 'DOCUMENT-SOURCE-CHANGE',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'download_policy' => 'signed',
            'version' => '1.0',
        ]);

        $sourceBytes = '%PDF-1.4 original source';
        $replacementBytes = '%PDF-1.4 replacement source';
        Storage::disk('private')->put('products/source.pdf', $sourceBytes);
        Storage::disk('private')->put('products/replacement.pdf', $replacementBytes);

        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => 'products/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($sourceBytes),
            'sha256' => hash('sha256', $sourceBytes),
            'is_primary' => false,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $file->sha256,
            'processing_status' => 'pending',
        ]);

        $supervisor = Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('configured')->once()->andReturn(true);
        $supervisor->shouldReceive('reviewFile')->once()->andReturnUsing(
            function () use ($file, $replacementBytes): array {
                $file->update([
                    'path' => 'products/replacement.pdf',
                    'size_bytes' => strlen($replacementBytes),
                    'sha256' => hash('sha256', $replacementBytes),
                ]);

                return ['content_chunks' => []];
            }
        );
        $this->app->instance(ProductSupervisorClient::class, $supervisor);

        (new ProcessProductDocument($document->id))->handle($supervisor, app(StorageManager::class));

        $document->refresh();
        $this->assertSame('stale', $document->processing_status);
        $this->assertSame('source_changed_during_processing', $document->failure_code);
        $this->assertSame(0, $document->chunks()->count());
        $this->assertSame(0, $document->pages()->count());
    }

}
