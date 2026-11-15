<?php

namespace Tests\Feature;

use App\Http\Controllers\StoreController;
use App\Jobs\GenerateProductDocumentAudio;
use App\Jobs\ProcessProductDocument;
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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    public function test_docx_reader_and_sample_artifacts_use_managed_storage_and_matching_previews(): void
    {
        Storage::fake('private');
        Bus::fake();

        $docxBytes = 'test-docx-source-bytes';
        $readerPdfBytes = "%PDF-1.4\nreader-preview";
        $samplePdfBytes = "%PDF-1.4\nsample-preview";
        Storage::disk('private')->put('sources/source.docx', $docxBytes);

        $category = StoreCategory::create([
            'name' => 'دسته آزمون سند',
            'slug' => 'document-storage-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمون سند',
            'slug' => 'document-storage-product',
            'sku' => 'DOCUMENT-STORAGE-TEST',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'download_policy' => 'reader_only',
            'version' => '1.0',
        ]);
        $productFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => 'sources/source.docx',
            'original_name' => 'source.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => strlen($docxBytes),
            'sha256' => hash('sha256', $docxBytes),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $productFile->id,
            'source_sha256' => hash('sha256', $docxBytes),
            'processing_status' => 'queued',
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Product document artifact volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $supervisor = \Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('reviewFile')->once()->andReturn([
            'review_id' => 'document-review-1',
            'product_id' => (string) $product->id,
            'inspection' => [
                'detected_type' => 'docx',
                'page_count' => 1,
                'visual_inventory_complete' => true,
                'embedded_image_count' => 0,
                'extraction_truncated' => false,
            ],
            'security_gate' => 'structural_checks_passed',
            'content_chunks' => [[
                'chunk_type' => 'docx_paragraph',
                'content_text' => 'متن آزمون برای خوانش سند.',
                'locator' => ['page' => 1, 'heading_level' => 2],
            ]],
            'facts' => [],
            'summary' => 'خلاصه آزمون',
            'findings' => [],
            'warnings' => [],
            'provider_status' => ['groq_text' => 'completed'],
            'media_reports' => [],
            'analysis_batch_count' => 1,
            'successful_analysis_batches' => 1,
            'analyzed_characters' => 30,
            'sample_pdf_base64' => base64_encode($samplePdfBytes),
            'sample_page_numbers' => [1],
        ]);

        $storeController = \Mockery::mock(StoreController::class);
        $storeController->shouldReceive('prepareDocxPreview')
            ->once()
            ->with($docxBytes)
            ->andReturn($readerPdfBytes);
        $this->app->instance(StoreController::class, $storeController);

        (new ProcessProductDocument($document->id))->handle($supervisor, app(StorageManager::class));

        $readerArtifact = $document->fresh()->artifacts()
            ->where('artifact_type', 'reader_pdf')
            ->where('version', 1)
            ->firstOrFail();
        $sampleArtifact = $document->fresh()->artifacts()
            ->where('artifact_type', 'sample_pdf')
            ->where('version', 1)
            ->firstOrFail();

        $this->assertSame('storage_volume:'.$volume->id, $readerArtifact->disk);
        $this->assertSame('storage_volume:'.$volume->id, $sampleArtifact->disk);

        foreach ([$readerArtifact, $sampleArtifact] as $artifact) {
            $managed = StorageManagedFile::query()
                ->where('source_type', StoreProductDocumentArtifact::class)
                ->where('source_id', $artifact->id)
                ->firstOrFail();

            $this->assertSame($artifact->path, $managed->file_key);
            $this->assertSame(false, data_get($managed->metadata, 'reference_pending'));
            $this->assertSame('active', $managed->status);
            $this->assertTrue(app(StorageManager::class)->exists($artifact->disk, $artifact->path));
        }

        $samplePreview = StoreProductPreview::query()
            ->where('product_id', $product->id)
            ->where('access_level', 'sample')
            ->firstOrFail();
        $fullPreview = StoreProductPreview::query()
            ->where('product_id', $product->id)
            ->where('access_level', 'full')
            ->firstOrFail();

        $this->assertSame($sampleArtifact->disk, $samplePreview->disk);
        $this->assertSame($sampleArtifact->path, $samplePreview->path);
        $this->assertSame($readerArtifact->disk, $fullPreview->disk);
        $this->assertSame($readerArtifact->path, $fullPreview->path);
        $this->assertSame($readerPdfBytes, app(StorageManager::class)->get($readerArtifact->disk, $readerArtifact->path));
        $this->assertSame($samplePdfBytes, app(StorageManager::class)->get($sampleArtifact->disk, $sampleArtifact->path));
        $this->assertSame('completed', $document->fresh()->processing_status);

        Bus::assertDispatched(GenerateProductDocumentAudio::class);
    }
}
