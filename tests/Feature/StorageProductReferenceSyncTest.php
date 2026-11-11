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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageProductReferenceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function product(): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته آزمون',
            'slug' => 'storage-reference-tests',
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمون ذخیره‌سازی',
            'slug' => 'storage-reference-product',
            'sku' => 'STORAGE-REFERENCE',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
            'version' => '1.0',
        ]);
    }

    private function volume(): StorageVolume
    {
        return StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Storage reference test volume',
            'driver' => 'local',
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);
    }

    private function managedReference(
        StorageVolume $volume,
        string $sourceType,
        int $sourceId,
        string $newKey,
        array $metadata = []
    ): StorageManagedFile {
        return StorageManagedFile::create([
            'storage_volume_id' => $volume->id,
            'drive_key' => $volume->drive_key,
            'file_key' => $newKey,
            'file_key_hash' => hash('sha256', $newKey),
            'original_name' => basename($newKey),
            'mime' => 'application/pdf',
            'size_bytes' => 128,
            'checksum' => hash('sha256', $newKey),
            'classification' => 'critical',
            'source_module' => 'store-product-artifact',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'product_id' => null,
            'visibility' => 'private',
            'status' => 'active',
            'metadata' => $metadata,
        ]);
    }

    public function test_preview_audio_reference_uses_its_declared_source_field(): void
    {
        $product = $this->product();
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'legacy/preview.pdf',
            'mime' => 'application/pdf',
            'audio_disk' => 'private',
            'audio_path' => 'legacy/audio.wav',
            'audio_mime' => 'audio/wav',
            'page_number' => 1,
            'kind' => 'pdf',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $volume = $this->volume();
        $managed = $this->managedReference($volume, StoreProductPreview::class, $preview->id, 'products/audio/new.wav', [
            'source_field' => 'audio_path',
        ]);

        app(StorageTransferService::class)->syncSourceReference($managed, 'storage_volume:'.$volume->id);

        $preview->refresh();
        $this->assertSame('private', $preview->disk);
        $this->assertSame('legacy/preview.pdf', $preview->path);
        $this->assertSame('storage_volume:'.$volume->id, $preview->audio_disk);
        $this->assertSame('products/audio/new.wav', $preview->audio_path);
    }

    public function test_document_artifact_transfer_updates_all_preview_mirrors(): void
    {
        $product = $this->product();
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
        $volume = $this->volume();
        $managed = $this->managedReference($volume, StoreProductDocumentArtifact::class, $artifact->id, 'products/artifacts/new-audio.wav');

        app(StorageTransferService::class)->syncSourceReference($managed, 'storage_volume:'.$volume->id);

        $artifact->refresh();
        $preview->refresh();
        $newDisk = 'storage_volume:'.$volume->id;
        $this->assertSame($newDisk, $artifact->disk);
        $this->assertSame('products/artifacts/new-audio.wav', $artifact->path);
        $this->assertSame($newDisk, $preview->disk);
        $this->assertSame('products/artifacts/new-audio.wav', $preview->path);
        $this->assertSame($newDisk, $preview->audio_disk);
        $this->assertSame('products/artifacts/new-audio.wav', $preview->audio_path);
    }

    public function test_product_file_transfer_updates_full_reader_preview_path(): void
    {
        $product = $this->product();
        $productFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => 'legacy/full-reader.pdf',
            'original_name' => 'full-reader.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 256,
            'sha256' => hash('sha256', 'reader'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'legacy/full-reader.pdf',
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'reader_document',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $volume = $this->volume();
        $managed = $this->managedReference($volume, StoreProductFile::class, $productFile->id, 'products/files/new-reader.pdf');

        app(StorageTransferService::class)->syncSourceReference($managed, 'storage_volume:'.$volume->id);

        $productFile->refresh();
        $preview->refresh();
        $this->assertSame('storage_volume:'.$volume->id, $productFile->disk);
        $this->assertSame('products/files/new-reader.pdf', $productFile->path);
        $this->assertSame('storage_volume:'.$volume->id, $preview->disk);
        $this->assertSame('products/files/new-reader.pdf', $preview->path);
    }

    public function test_legacy_product_files_and_reader_artifacts_migrate_once_and_update_preview_mirrors(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        $product = $this->product();

        $legacyProductFilePath = 'legacy/product-source.docx';
        Storage::disk('public')->put($legacyProductFilePath, 'legacy product source');
        $productFile = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'public',
            'path' => $legacyProductFilePath,
            'original_name' => 'product-source.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => strlen('legacy product source'),
            'sha256' => hash('sha256', 'legacy product source'),
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
        ]);

        $legacyArtifactPath = 'legacy/reader-sample.pdf';
        Storage::disk('private')->put($legacyArtifactPath, '%PDF-1.4 legacy sample');
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $productFile->id,
            'source_sha256' => hash('sha256', 'legacy product source'),
            'processing_status' => 'completed',
        ]);
        $artifact = StoreProductDocumentArtifact::create([
            'document_id' => $document->id,
            'artifact_type' => 'sample_pdf',
            'version' => 1,
            'disk' => 'private',
            'path' => $legacyArtifactPath,
            'mime' => 'application/pdf',
            'size_bytes' => strlen('%PDF-1.4 legacy sample'),
            'sha256' => hash('sha256', '%PDF-1.4 legacy sample'),
        ]);
        $artifactPreview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => $legacyArtifactPath,
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'pdf',
            'access_level' => 'sample',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $filePreview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => $legacyProductFilePath,
            'mime' => 'application/pdf',
            'page_number' => 1,
            'kind' => 'reader_document',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->assertSame(0, Artisan::call('storage:migrate-legacy-product-assets', ['--limit' => 20]));

        $productFile->refresh();
        $artifact->refresh();
        $artifactPreview->refresh();
        $filePreview->refresh();

        $this->assertSame('private', $productFile->disk);
        $this->assertNotSame($legacyProductFilePath, $productFile->path);
        $this->assertTrue(Storage::disk('private')->exists($productFile->path));
        $this->assertFalse(Storage::disk('public')->exists($legacyProductFilePath));
        $this->assertSame($productFile->disk, $filePreview->disk);
        $this->assertSame($productFile->path, $filePreview->path);

        $this->assertSame('private', $artifact->disk);
        $this->assertNotSame($legacyArtifactPath, $artifact->path);
        $this->assertTrue(Storage::disk('private')->exists($artifact->path));
        $this->assertFalse(Storage::disk('private')->exists($legacyArtifactPath));
        $this->assertSame($artifact->disk, $artifactPreview->disk);
        $this->assertSame($artifact->path, $artifactPreview->path);

        $this->assertDatabaseHas('storage_managed_files', [
            'source_type' => StoreProductFile::class,
            'source_id' => $productFile->id,
            'file_key' => $productFile->path,
        ]);
        $this->assertDatabaseHas('storage_managed_files', [
            'source_type' => StoreProductDocumentArtifact::class,
            'source_id' => $artifact->id,
            'file_key' => $artifact->path,
        ]);

        $this->assertSame(0, Artisan::call('storage:migrate-legacy-product-assets', ['--limit' => 20]));
        $this->assertSame(2, StorageManagedFile::query()->whereIn('source_type', [StoreProductFile::class, StoreProductDocumentArtifact::class])->count());
    }

}
