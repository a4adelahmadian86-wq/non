<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductDocument;
use App\Models\StorageVolume;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use App\Services\ProductSupervisorClient;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RetryOfflineProductDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        }

        parent::tearDown();
    }

    public function test_offline_extracted_document_is_requeued_when_primary_ai_is_ready(): void
    {
        Queue::fake();
        $document = $this->createOfflineDocument();

        $supervisor = Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('primaryReadyForAi')->once()->andReturn(true);
        $this->app->instance(ProductSupervisorClient::class, $supervisor);

        $this->artisan('farast:retry-offline-product-documents --limit=10')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame('pending', $document->processing_status);
        $this->assertGreaterThan(
            now()->timestamp,
            (int) data_get($document->structure_json, 'ai_review.offline_retry_after')
        );

        Queue::assertPushed(ProcessProductDocument::class, fn (ProcessProductDocument $job) =>
            $job->documentId === (int) $document->id
        );
    }

    public function test_offline_document_remains_untouched_when_primary_ai_is_unavailable(): void
    {
        Queue::fake();
        $document = $this->createOfflineDocument();

        $supervisor = Mockery::mock(ProductSupervisorClient::class);
        $supervisor->shouldReceive('primaryReadyForAi')->once()->andReturn(false);
        $this->app->instance(ProductSupervisorClient::class, $supervisor);

        $this->artisan('farast:retry-offline-product-documents --limit=10')
            ->assertExitCode(0);

        $document->refresh();
        $this->assertSame('extracted', $document->processing_status);
        Queue::assertNothingPushed();
    }

    private function createOfflineDocument(): StoreProductDocument
    {
        $category = StoreCategory::create([
            'name' => 'دسته بازیابی آفلاین',
            'slug' => 'offline-document-retry-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول بازیابی آفلاین',
            'slug' => 'offline-document-retry-product',
            'sku' => 'OFFLINE-DOCUMENT-RETRY',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'download_policy' => 'reader_only',
            'version' => '1.0',
        ]);

        StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Offline retry source volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $sourceBytes = 'Source bytes for offline product document retry.';
        $storage = app(StorageManager::class);
        $managed = $storage->storeContents(
            $sourceBytes,
            'offline-source.txt',
            'text/plain',
            StorageManager::CRITICAL,
            'store-product-file',
            StoreProductFile::class,
            null,
            $product->id
        );

        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => $managed['disk'],
            'path' => $managed['path'],
            'original_name' => 'offline-source.txt',
            'mime' => 'text/plain',
            'size_bytes' => strlen($sourceBytes),
            'sha256' => hash('sha256', $sourceBytes),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $storage->bindReference(
            (int) $managed['managed_file_id'],
            StoreProductFile::class,
            (int) $file->id,
            (int) $product->id
        );

        return StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => hash('sha256', $sourceBytes),
            'processing_status' => 'extracted',
            'structure_json' => [
                'ai_review' => [
                    'processing_mode' => 'offline_structural_only',
                    'offline_retry_after' => now()->subMinute()->timestamp,
                ],
            ],
        ]);
    }
}
