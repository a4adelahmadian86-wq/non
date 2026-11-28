<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Models\StorageVolume;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StorageBackupPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_does_not_read_source_files_when_no_healthy_destination_exists(): void
    {
        config(['storage_management.backup.encryption_key' => '', 'storage_management.backup.enable_iran_replica' => false]);

        StorageVolume::forceCreate([
            'drive_key' => 'iran_primary',
            'name' => 'Iran primary only',
            'driver' => 's3',
            'endpoint' => 'https://iran.example.test',
            'bucket' => 'farast-primary',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldNotReceive('downloadTo');
        $storage->shouldNotReceive('uploadBackup');

        try {
            (new StorageBackupService($storage))->runWeekly('manual');
            $this->fail('A backup without a configured destination must fail before creating an archive.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('هیچ مقصد سالمی', $exception->getMessage());
        }

        $this->assertDatabaseHas('storage_backup_runs', [
            'backup_type' => 'manual',
            'source_name' => 'application',
            'status' => 'failed',
            'error_message' => 'هیچ مقصد سالمی برای بکاپ پیکربندی نشده است؛ برای جلوگیری از مصرف ترافیک، آرشیو ساخته نشد.',
        ]);

        $run = StorageBackupRun::query()
            ->where('backup_type', 'manual')
            ->where('source_name', 'application')
            ->sole();

        $this->assertTrue((bool) data_get($run->metadata, 'archive_skipped'));
        $this->assertSame('no_healthy_destination', data_get($run->metadata, 'reason'));
    }
}
