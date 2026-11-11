<?php

namespace Tests\Feature;

use App\Models\StoreProductIngestion;
use App\Models\StoreProductIntakeSession;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\StoreProductIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreProductIntakeSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_session_and_ingestion_can_exist_before_catalog_product_creation(): void
    {
        $user = User::factory()->create();

        $session = StoreProductIntakeSession::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'product_type' => 'digital_file',
            'status' => 'selecting_files',
            'expires_at' => now()->addHours(2),
        ]);

        $ingestion = StoreProductIngestion::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => null,
            'intake_session_id' => $session->id,
            'user_id' => $user->id,
            'status' => 'inspected',
            'staging_disk' => 'private',
            'staging_path' => 'store/quarantine/intake/'.$session->id.'/sample.pdf',
            'original_name' => 'sample.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'inspection_json' => ['safe' => true, 'scan' => 'farast_static_v1'],
            'is_primary' => true,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertNull($ingestion->product_id);
        $this->assertSame($session->id, $ingestion->intakeSession->id);
        $this->assertSame('digital_file', $session->fresh()->product_type);
        $this->assertDatabaseHas('store_product_ingestions', [
            'id' => $ingestion->id,
            'product_id' => null,
            'intake_session_id' => $session->id,
        ]);
        $this->assertTrue(Schema::hasColumns('store_product_ingestions', [
            'storage_disk',
            'storage_path',
            'managed_file_id',
            'supervisor_status',
            'supervisor_review_json',
            'antivirus_status',
        ]));
    }
    public function test_cancelled_ingestions_do_not_block_intake_completion_counters(): void
    {
        $user = User::factory()->create();
        $session = StoreProductIntakeSession::create([
            'id' => (string) \\Illuminate\\Support\\Str::uuid(),
            'user_id' => $user->id,
            'product_type' => 'digital_file',
            'status' => 'selecting_files',
            'expires_at' => now()->addHours(2),
        ]);

        StoreProductIngestion::create([
            'id' => (string) \\Illuminate\\Support\\Str::uuid(),
            'product_id' => null,
            'intake_session_id' => $session->id,
            'user_id' => $user->id,
            'status' => 'rejected',
            'staging_disk' => 'private',
            'staging_path' => 'cancelled',
            'original_name' => 'rejected.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 2048,
            'sha256' => str_repeat('b', 64),
            'inspection_json' => ['safe' => true],
            'is_primary' => false,
            'supervisor_status' => 'cancelled',
            'expires_at' => now()->addMinutes(30),
        ]);

        $progress = app(StoreProductIntakeService::class)->progress($session);

        $this->assertSame(0, $progress['file_count']);
        $this->assertSame(0, $progress['uploaded_count']);
        $this->assertSame(1, $progress['cancelled_count']);
        $this->assertSame(0, $progress['total_size_bytes']);
    }

    public function test_product_intake_permissions_are_registered_and_scoped_to_management_roles(): void
    {
        $manager = User::factory()->create(['role' => 'organization_manager']);
        $supervisor = User::factory()->create(['role' => 'team_supervisor']);
        $employee = User::factory()->create(['role' => 'employee']);
        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->allows($manager, 'store.create_products'));
        $this->assertTrue($authorization->allows($manager, 'store.publish_products'));
        $this->assertTrue($authorization->allows($supervisor, 'store.create_products'));
        $this->assertTrue($authorization->allows($supervisor, 'store.publish_products'));
        $this->assertFalse($authorization->allows($employee, 'store.create_products'));
        $this->assertFalse($authorization->allows($employee, 'store.publish_products'));
    }

}
