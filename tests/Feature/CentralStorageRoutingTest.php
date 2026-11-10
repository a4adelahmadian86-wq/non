<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CentralStorageRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_critical_uploads_are_routed_to_the_foreign_logical_drive(): void
    {
        Storage::fake('private');
        Storage::forgetDisk('managed');

        $stored = Storage::disk('managed')->put('ticket-attachments/42/example.txt', 'support file', [
            'farast_classification' => 'non_critical',
            'farast_source_type' => 'ticket_attachment',
            'farast_source_id' => 42,
            'mimetype' => 'text/plain',
        ]);

        $this->assertTrue($stored);
        $record = StorageManagedFile::query()->where('logical_path', 'ticket-attachments/42/example.txt')->firstOrFail();
        $this->assertSame('foreign_primary', $record->drive_key);
        $this->assertSame('non_critical', $record->classification);
        $this->assertSame('ticket_attachment', $record->source_type);
        $this->assertSame(42, $record->source_id);
        $this->assertSame('support file', Storage::disk('managed')->get('ticket-attachments/42/example.txt'));
    }

    public function test_critical_uploads_are_routed_to_the_iranian_logical_drive(): void
    {
        Storage::forgetDisk('managed');

        Storage::disk('managed')->put('store/products/7/manual.pdf', 'critical product file', [
            'farast_classification' => 'critical',
            'farast_product_id' => 7,
            'mimetype' => 'application/pdf',
        ]);

        $record = StorageManagedFile::query()->where('logical_path', 'store/products/7/manual.pdf')->firstOrFail();
        $this->assertSame('iran_primary', $record->drive_key);
        $this->assertSame(7, $record->product_id);
    }

    public function test_capacity_reservations_prevent_overfilling_a_volume(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Limited local test volume',
            'driver' => 'local',
            'capacity_bytes' => 5,
            'used_bytes' => 0,
            'reserved_bytes' => 0,
            'reserved_files' => 0,
            'priority' => 1,
            'is_active' => true,
            'status' => 'healthy',
        ]);

        Storage::forgetDisk('managed');
        Storage::disk('managed')->put('store/products/1/large.bin', '123456');

        $record = StorageManagedFile::query()->where('logical_path', 'store/products/1/large.bin')->firstOrFail();
        $this->assertNotSame($volume->id, $record->storage_volume_id);
        $this->assertSame('iran_primary', $record->drive_key);
        $this->assertSame(0, (int) $volume->fresh()->used_bytes);
        $this->assertSame(0, (int) $volume->fresh()->reserved_bytes);
    }
}