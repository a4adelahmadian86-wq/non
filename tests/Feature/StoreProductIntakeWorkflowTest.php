<?php

namespace Tests\Feature;

use App\Jobs\ReviewProductIntakeFile;
use App\Models\StoreProduct;
use App\Models\StoreProductIngestion;
use App\Models\StoreProductIntakeSession;
use App\Models\User;
use App\Services\Storage\StorageManager;
use App\Services\UploadSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreProductIntakeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Intake Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_file_is_staged_inspected_and_adopted_before_any_catalog_product_exists(): void
    {
        Storage::fake('private');
        Queue::fake();
        $admin = $this->admin();
        $contents = "این فایل پیش از ثبت مشخصات محصول بررسی می‌شود.";
        $checksum = hash('sha256', $contents);
        $size = strlen($contents);

        $sessionResponse = $this->actingAs($admin)->postJson(route('admin.store.intakes.start'), [
            'product_type' => 'digital_file',
        ])->assertCreated();
        $sessionId = $sessionResponse->json('intake_session_id');

        $this->mock(UploadSecurityService::class, function ($mock) use ($checksum, $size) {
            $mock->shouldReceive('inspect')->twice()->andReturn([
                'safe' => true,
                'mime' => 'text/plain',
                'size' => $size,
                'checksum' => $checksum,
                'scan' => 'farast_static_v1',
                'scan_label' => 'بررسی ساختاری داخلی فراست',
                'risk_level' => 'low',
                'detected_type' => 'text',
                'archive_entry_count' => 0,
                'archive_expanded_bytes' => 0,
                'findings' => [],
                'warnings' => [],
            ]);
        });

        $preflight = $this->actingAs($admin)->post(
            route('admin.store.intakes.files.preflight', $sessionId),
            ['file' => UploadedFile::fake()->createWithContent('guide.txt', $contents), 'is_primary' => '1'],
            ['Accept' => 'application/json']
        )->assertCreated();

        $ingestionId = $preflight->json('ingestion_id');
        $this->assertDatabaseHas('store_product_ingestions', [
            'id' => $ingestionId,
            'product_id' => null,
            'intake_session_id' => $sessionId,
            'status' => 'inspected',
        ]);
        $this->assertDatabaseCount('store_products', 0);

        $this->actingAs($admin)
            ->postJson(route('admin.store.intakes.files.confirm', [$sessionId, $ingestionId]))
            ->assertOk()
            ->assertJsonPath('session.status', 'review_pending')
            ->assertJsonPath('file.supervisor_status', 'queued');

        $ingestion = StoreProductIngestion::query()->findOrFail($ingestionId);
        $this->assertNull($ingestion->product_id);
        $this->assertSame('uploaded', $ingestion->status);
        $this->assertNotNull($ingestion->managed_file_id);
        $this->assertTrue(app(StorageManager::class)->exists($ingestion->storage_disk, $ingestion->storage_path));
        $this->assertDatabaseCount('store_products', 0);
        Queue::assertPushed(ReviewProductIntakeFile::class);
    }

    public function test_intake_session_cannot_be_read_or_modified_by_another_admin(): void
    {
        $owner = $this->admin();
        $otherAdmin = User::create([
            'name' => 'Other Admin',
            'mobile' => '09123456780',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $session = StoreProductIntakeSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $owner->id,
            'product_type' => 'course',
            'status' => 'selecting_files',
            'expires_at' => now()->addHours(2),
        ]);

        $this->actingAs($otherAdmin)
            ->getJson(route('admin.store.intakes.progress', $session))
            ->assertNotFound();
    }
}
