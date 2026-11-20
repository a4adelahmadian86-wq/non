<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductDocumentAudio;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StoreProductDocumentChunk;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Services\ProductDocumentAudioService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerateProductDocumentAudioStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        }
        parent::tearDown();
    }

    public function test_generated_audio_is_managed_and_reader_preview_references_follow_it(): void
    {
        config([
            'services.document_audio.url' => 'https://document-audio.example.test/v1/synthesize',
            'services.document_audio.token' => 'test-token',
            'services.document_audio.voice_id' => 'farast-document-fa-male-37-v1',
        ]);

        $pcm = str_repeat("\0", 1600);
        $format = pack('vvVVvv', 1, 1, 16000, 32000, 2, 16);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('V', 16).$format.'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake([
            'document-audio.example.test/*' => Http::response($wav, 200, ['Content-Type' => 'audio/wav']),
        ]);

        $category = StoreCategory::create([
            'name' => 'دسته آزمون صوت',
            'slug' => 'audio-storage-tests',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمون صوت',
            'slug' => 'audio-storage-product',
            'sku' => 'AUDIO-STORAGE-TEST',
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
        $document->chunks()->create([
            'chunk_index' => 0,
            'chunk_type' => 'page_text',
            'locator_json' => ['page' => 1],
            'content_text' => 'متن آماده برای تولید صوت.',
            'content_sha256' => hash('sha256', 'متن آماده برای تولید صوت.'),
            'metadata_json' => [],
        ]);
        StoreProductDocumentArtifact::create([
            'document_id' => $document->id,
            'artifact_type' => 'audio_full_wav',
            'version' => 3,
            'disk' => 'private',
            'path' => 'legacy/old-audio.wav',
            'mime' => 'audio/wav',
            'size_bytes' => 128,
            'sha256' => hash('sha256', 'old-audio'),
            'metadata_json' => ['generated_once' => true],
        ]);
        $preview = StoreProductPreview::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'legacy/old-audio.wav',
            'mime' => 'audio/wav',
            'page_number' => 1,
            'kind' => 'reader_document',
            'access_level' => 'full',
            'watermarked' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Reader audio artifact volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $job = new GenerateProductDocumentAudio($document->id);
        $job->handle(app(ProductDocumentAudioService::class), app(StorageManager::class));

        $artifact = $document->fresh()->artifacts()
            ->where('artifact_type', 'audio_full_wav')
            ->where('version', 3)
            ->firstOrFail();
        $managed = StorageManagedFile::query()
            ->where('source_type', StoreProductDocumentArtifact::class)
            ->where('source_id', $artifact->id)
            ->firstOrFail();
        $preview->refresh();

        // The fixture has no FARA provider alignment chunk, so these word intervals are estimates.
        $this->assertSame('estimated', data_get($artifact->metadata_json, 'segments.0.alignment_quality'));
        $this->assertNotEmpty(data_get($artifact->metadata_json, 'segments.0.words'));
        $this->assertSame('estimated_word_timing', data_get($artifact->metadata_json, 'granularity'));
        $this->assertSame('estimated_word_timing', data_get($document->fresh()->structure_json, 'audio.granularity'));
        $this->assertSame('estimated', data_get($preview->audio_timing, '0.alignment_quality'));

        $this->assertSame('storage_volume:'.$volume->id, $artifact->disk);
        $this->assertSame($artifact->path, $managed->file_key);
        $this->assertSame(false, data_get($managed->metadata, 'reference_pending'));
        $this->assertSame('active', $managed->status);
        $this->assertSame('storage_volume:'.$volume->id, $preview->disk);
        $this->assertSame($artifact->path, $preview->path);
        $this->assertSame('storage_volume:'.$volume->id, $preview->audio_disk);
        $this->assertSame($artifact->path, $preview->audio_path);
        $this->assertSame('audio/wav', $preview->audio_mime);
        $this->assertNotEmpty($preview->audio_timing);
        $this->assertTrue(app(StorageManager::class)->exists($artifact->disk, $artifact->path));
        $this->assertStringStartsWith('RIFF', app(StorageManager::class)->get($artifact->disk, $artifact->path));
        Http::assertSentCount(1);
    }
}
