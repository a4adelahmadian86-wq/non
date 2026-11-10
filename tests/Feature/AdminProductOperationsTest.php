<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Operations Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function document(string $status = 'failed', string $contents = 'immutable product source'): StoreProductDocument
    {
        Storage::fake('private');
        $category = StoreCategory::create(['name' => 'دسته عملیات', 'slug' => 'operations-category', 'is_active' => true]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول عملیات',
            'slug' => 'operations-product',
            'sku' => 'OPS-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);
        $path = 'store/products/'.$product->id.'/source.pdf';
        Storage::disk('private')->put($path, $contents);
        $hash = hash('sha256', $contents);
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
            'scan_engine' => 'farast_static_v1',
            'scanned_at' => now(),
        ]);

        return StoreProductDocument::create([
            'product_id' => $product->id,
            'product_file_id' => $file->id,
            'source_sha256' => $hash,
            'processing_status' => $status,
            'failure_code' => 'previous_failure',
        ]);
    }

    public function test_admin_can_retry_a_failed_document_after_private_source_hash_is_verified(): void
    {
        Queue::fake();
        config(['services.product_supervisor.url' => 'https://supervisor.example.test', 'services.product_supervisor.token' => 'test-token']);
        $document = $this->document();

        $this->actingAs($this->admin())
            ->from(route('admin.store.operations'))
            ->post(route('admin.store.operations.documents.retry', $document))
            ->assertRedirect(route('admin.store.operations'))
            ->assertSessionHas('status');

        $this->assertSame('pending', $document->fresh()->processing_status);
        $this->assertNull($document->fresh()->failure_code);
        Queue::assertPushed(ProcessProductDocument::class, fn ($job) => $job->documentId === $document->id);
        $this->assertDatabaseHas('farast_audit_events', [
            'event_type' => 'store.product_document.manual_retry_queued',
            'aggregate_id' => $document->id,
        ]);
    }

    public function test_retry_rejects_changed_source_bytes_without_dispatching_a_job(): void
    {
        Queue::fake();
        config(['services.product_supervisor.url' => 'https://supervisor.example.test', 'services.product_supervisor.token' => 'test-token']);
        $document = $this->document();
        Storage::disk('private')->put($document->file->path, 'changed source bytes');

        $this->actingAs($this->admin())
            ->from(route('admin.store.operations'))
            ->post(route('admin.store.operations.documents.retry', $document))
            ->assertRedirect(route('admin.store.operations'))
            ->assertSessionHasErrors('document');

        $this->assertSame('failed', $document->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_retry_never_requeues_pending_or_processing_documents(): void
    {
        Queue::fake();
        config(['services.product_supervisor.url' => 'https://supervisor.example.test', 'services.product_supervisor.token' => 'test-token']);
        $document = $this->document('processing');

        $this->actingAs($this->admin())
            ->from(route('admin.store.operations'))
            ->post(route('admin.store.operations.documents.retry', $document))
            ->assertRedirect(route('admin.store.operations'))
            ->assertSessionHasErrors('document');

        $this->assertSame('processing', $document->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_retry_rejects_files_above_the_current_supervisor_limit(): void
    {
        Queue::fake();
        config(['services.product_supervisor.url' => 'https://supervisor.example.test', 'services.product_supervisor.token' => 'test-token']);
        $document = $this->document('awaiting_provider');
        $document->file->update(['size_bytes' => 26 * 1024 * 1024]);

        $this->actingAs($this->admin())
            ->from(route('admin.store.operations'))
            ->post(route('admin.store.operations.documents.retry', $document))
            ->assertRedirect(route('admin.store.operations'))
            ->assertSessionHasErrors('document');

        $this->assertSame('awaiting_provider', $document->fresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_operations_center_requires_admin_authentication(): void
    {
        $this->get(route('admin.store.operations'))->assertRedirect(route('login'));
    }
}
