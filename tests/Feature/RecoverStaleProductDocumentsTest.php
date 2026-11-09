<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecoverStaleProductDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
    }

    private function document(string $status, string $contents, int $minutesOld = 40): StoreProductDocument
    {
        $category = StoreCategory::create([
            'name' => 'Recovery category',
            'slug' => 'recovery-category-'.uniqid(),
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'Recovery product',
            'slug' => 'recovery-product-'.uniqid(),
            'sku' => 'RECOVERY-'.uniqid(),
            'type' => 'digital_file',
            'price_rials' => 1000,
            'status' => 'draft',
        ]);
        $hash = hash('sha256', $contents);
        $path = 'store/products/'.$product->id.'/source.pdf';
        Storage::disk('private')->put($path, $contents);
        $file = StoreProductFile::create([
            'product_id' => $product->id,
            'purpose' => 'product_download',
            'version' => 1,
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => $hash,
            'is_primary' => true,
            'is_active' => true,
            'scan_status' => 'clean',
            'scan_engine' => 'test',
            'scanned_at' => now(),
        ]);
        $document = StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => $status,
            'failure_code' => 'old_worker',
        ]);
        $document->forceFill([
            'created_at' => now()->subMinutes($minutesOld),
            'updated_at' => now()->subMinutes($minutesOld),
        ])->save();

        return $document;
    }

    public function test_abandoned_processing_document_is_requeued_only_after_source_checksum_verification(): void
    {
        $document = $this->document('processing', 'immutable source bytes');

        $this->artisan('farast:recover-product-documents')
            ->expectsOutputToContain('Recovered 1 product document(s)')
            ->assertExitCode(0);

        $this->assertSame('pending', $document->fresh()->processing_status);
        $this->assertNull($document->fresh()->failure_code);
        Queue::assertPushed(ProcessProductDocument::class, fn ($job) => $job->documentId === $document->id);
        $this->assertDatabaseHas('farast_audit_events', [
            'event_type' => 'store.product_document.recovery_queued',
            'aggregate_id' => $document->id,
        ]);
    }

    public function test_recovery_never_requeues_failed_documents_or_changed_source_files(): void
    {
        $failed = $this->document('failed', 'failed source');
        $changed = $this->document('processing', 'original source');
        Storage::disk('private')->put($changed->file->path, 'tampered source');

        $this->artisan('farast:recover-product-documents')->assertExitCode(0);

        $this->assertSame('failed', $failed->fresh()->processing_status);
        $this->assertSame('processing', $changed->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_recent_processing_document_is_not_requeued(): void
    {
        $document = $this->document('processing', 'recent source', 5);

        $this->artisan('farast:recover-product-documents')->assertExitCode(0);

        $this->assertSame('processing', $document->fresh()->processing_status);
        Queue::assertNothingPushed();
    }
}
