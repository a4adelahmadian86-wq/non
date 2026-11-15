<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Services\Storage\StorageTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageProductArtifactReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        }
        parent::tearDown();
    }

    public function test_transferred_document_artifact_updates_all_reader_preview_mirrors(): void
    {
        $category = StoreCategory::create([
            'name' => 'دسته آزمون',
            'slug' => 'storage-artifact-reference-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمون ذخیره‌سازی artifact',
            'slug' => 'storage-artifact-reference-product',
            'sku' => 'STORAGE-ARTIFACT-REFERENCE',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'version' => '1.0',
        ]);
        $productFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => 'legacy/source.docx',
            'original_name' => 'source.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 256,
            'sha256' => hash('sha256', 'source'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $productFile->id,
            'source_sha256' => hash('sha256', 'source'),
            'processing_status' => 'completed',
        ]);
        $artifact = StoreProductDocumentArtifact::create([
            'document_id' => $document->id,
            'artifact_type' => 'audio_full_wav',
            'version' => 3,
            'disk' => 'private',
            'path' => 'legacy/shared-audio.wav',
            'mime' => 'audio/wav',
            'size_bytes' => 128,
            'sha256' => hash('sha256', 'audio'),
        ]);
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'legacy/shared-audio.wav',
            'mime' => 'audio/wav',
            'audio_disk' => 'private',
            'audio_path' => 'legacy/shared-audio.wav',
            'audio_mime' => 'audio/wav',
            'page_number' => 1,
            'kind' => 'reader_document',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => false,
            'sort_order' => 1,
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Storage artifact reference volume',
            'driver' => 'local',
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);
        $managed = StorageManagedFile::create([
            'storage_volume_id' => $volume->id,
            'drive_key' => 'iran_primary',
            'file_key' => 'legacy/shared-audio.wav',
            'file_key_hash' => hash('sha256', 'legacy/shared-audio.wav'),
            'original_name' => 'new-audio.wav',
            'mime' => 'audio/wav',
            'size_bytes' => 128,
            'checksum' => hash('sha256', 'audio'),
            'classification' => 'critical',
            'source_module' => 'store-product-artifact',
            'source_type' => StoreProductDocumentArtifact::class,
            'source_id' => $artifact->id,
            'product_id' => $product->id,
            'visibility' => 'private',
            'status' => 'active',
            'metadata' => ['disk' => 'storage_volume:'.$volume->id, 'reference_pending' => false],
        ]);

        app(StorageTransferService::class)->syncSourceReference($managed, 'storage_volume:'.$volume->id);

        $artifact->refresh();
        $preview->refresh();
        $newDisk = 'storage_volume:'.$volume->id;
        $this->assertSame($newDisk, $artifact->disk);
        $this->assertSame('legacy/shared-audio.wav', $artifact->path);
        $this->assertSame($newDisk, $preview->disk);
        $this->assertSame('legacy/shared-audio.wav', $preview->path);
        $this->assertSame($newDisk, $preview->audio_disk);
        $this->assertSame('legacy/shared-audio.wav', $preview->audio_path);
        $otherVolume = StorageVolume::create([
            'drive_key' => 'foreign_primary',
            'name' => 'Stale transfer must not win',
            'driver' => 'local',
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 2,
        ]);
        $staleManaged = StorageManagedFile::create([
            'storage_volume_id' => $otherVolume->id,
            'drive_key' => 'foreign_primary',
            'file_key' => 'legacy/superseded-audio.wav',
            'file_key_hash' => hash('sha256', 'legacy/superseded-audio.wav'),
            'original_name' => 'superseded-audio.wav',
            'mime' => 'audio/wav',
            'size_bytes' => 128,
            'checksum' => hash('sha256', 'old-audio'),
            'classification' => 'critical',
            'source_module' => 'store-product-artifact',
            'source_type' => StoreProductDocumentArtifact::class,
            'source_id' => $artifact->id,
            'product_id' => $product->id,
            'visibility' => 'private',
            'status' => 'active',
            'metadata' => ['disk' => 'storage_volume:'.$otherVolume->id, 'reference_pending' => false],
        ]);
        app(StorageTransferService::class)->syncSourceReference($staleManaged, 'storage_volume:'.$otherVolume->id);
        $artifact->refresh();
        $this->assertSame($newDisk, $artifact->disk);
        $this->assertSame('legacy/shared-audio.wav', $artifact->path);
    }

    public function test_transferred_reader_and_sample_pdf_artifacts_update_preview_disk_and_path(): void
    {
        $category = StoreCategory::create([
            'name' => 'دسته PDF',
            'slug' => 'storage-pdf-reference-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول PDF',
            'slug' => 'storage-pdf-reference-product',
            'sku' => 'STORAGE-PDF-REFERENCE',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'version' => '1.0',
        ]);
        $productFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => 'legacy/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 256,
            'sha256' => hash('sha256', 'source'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $productFile->id,
            'source_sha256' => hash('sha256', 'source'),
            'processing_status' => 'completed',
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'PDF artifact reference volume',
            'driver' => 'local',
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        foreach ([
            ['type' => 'reader_pdf', 'access' => 'full', 'path' => 'legacy/reader.pdf'],
            ['type' => 'sample_pdf', 'access' => 'sample', 'path' => 'legacy/sample.pdf'],
        ] as $index => $definition) {
            $artifact = StoreProductDocumentArtifact::create([
                'document_id' => $document->id,
                'artifact_type' => $definition['type'],
                'version' => 1,
                'disk' => 'private',
                'path' => $definition['path'],
                'mime' => 'application/pdf',
                'size_bytes' => 128,
                'sha256' => hash('sha256', $definition['path']),
            ]);
            $preview = StoreProductPreview::create([
                'product_id' => $product->id,
                'disk' => 'private',
                'path' => $definition['path'],
                'mime' => 'application/pdf',
                'page_number' => 1,
                'kind' => 'pdf',
                'access_level' => $definition['access'],
                'watermarked' => true,
                'is_active' => true,
                'sort_order' => $index,
            ]);
            $managed = StorageManagedFile::create([
                'storage_volume_id' => $volume->id,
                'drive_key' => 'iran_primary',
                'file_key' => $definition['path'],
                'file_key_hash' => hash('sha256', $definition['path']),
                'original_name' => basename($definition['path']),
                'mime' => 'application/pdf',
                'size_bytes' => 128,
                'checksum' => hash('sha256', $definition['path']),
                'classification' => 'critical',
                'source_module' => 'store-product-artifact',
                'source_type' => StoreProductDocumentArtifact::class,
                'source_id' => $artifact->id,
                'product_id' => $product->id,
                'visibility' => 'private',
                'status' => 'active',
                'metadata' => ['disk' => 'storage_volume:'.$volume->id, 'reference_pending' => false],
            ]);

            app(StorageTransferService::class)->syncSourceReference($managed, 'storage_volume:'.$volume->id);

            $artifact->refresh();
            $preview->refresh();
            $this->assertSame('storage_volume:'.$volume->id, $artifact->disk);
            $this->assertSame($definition['path'], $artifact->path);
            $this->assertSame($artifact->disk, $preview->disk);
            $this->assertSame($artifact->path, $preview->path);
        }
    }

}
