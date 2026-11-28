<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
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

    public function test_weekly_full_backup_skips_iran_primary_sources_by_default_even_with_external_target(): void
    {
        config([
            'storage_management.backup.encryption_key' => '',
            'storage_management.backup.enable_iran_replica' => false,
            'storage_management.backup.allow_iran_primary_full_source_reads' => false,
        ]);

        $iran = StorageVolume::forceCreate([
            'drive_key' => 'iran_primary',
            'name' => 'Iran primary',
            'driver' => 's3',
            'endpoint' => 'https://iran.example.test',
            'bucket' => 'farast-primary',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'External backup',
            'driver' => 's3',
            'provider' => 'tigris',
            'endpoint' => 'https://t3.storage.dev',
            'region' => 'auto',
            'bucket' => 'farast-backups',
            'credentials' => ['key' => 'test', 'secret' => 'test', 'region' => 'auto', 'use_path_style_endpoint' => false],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        StorageManagedFile::create([
            'storage_volume_id' => $iran->id,
            'drive_key' => 'iran_primary',
            'file_key' => 'products/private/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => str_repeat('a', 64),
            'classification' => 'critical',
            'status' => 'active',
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldNotReceive('downloadTo');
        $storage->shouldNotReceive('uploadBackup');

        try {
            (new StorageBackupService($storage))->runWeekly('weekly');
            $this->fail('A full backup must not read Iran primary objects without explicit approval.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('بکاپ کامل از iran_primary غیرفعال است', $exception->getMessage());
        }

        $run = StorageBackupRun::query()
            ->where('backup_type', 'weekly')
            ->where('source_name', 'application')
            ->sole();

        $this->assertSame('failed', $run->status);
        $this->assertTrue((bool) data_get($run->metadata, 'archive_skipped'));
        $this->assertSame('iran_primary_full_read_disabled', data_get($run->metadata, 'reason'));
    }


    public function test_arvan_endpoint_is_never_accepted_as_a_backup_destination(): void
    {
        config([
            'storage_management.backup.encryption_key' => '',
            'storage_management.backup.enable_iran_replica' => false,
        ]);

        StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'Misconfigured Arvan backup',
            'driver' => 's3',
            'endpoint' => 'https://s3.ir-thr-at1.arvanstorage.ir',
            'bucket' => 'farast-backup',
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
            $this->fail('Arvan endpoints must never be accepted as backup destinations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('هیچ مقصد سالمی', $exception->getMessage());
        }

        $run = StorageBackupRun::query()
            ->where('backup_type', 'manual')
            ->where('source_name', 'application')
            ->sole();

        $this->assertTrue((bool) data_get($run->metadata, 'archive_skipped'));
        $this->assertSame('no_healthy_destination', data_get($run->metadata, 'reason'));
    }


    public function test_full_backup_blocks_unbudgeted_foreign_s3_sources(): void
    {
        config([
            'storage_management.backup.encryption_key' => str_repeat('backup-key-', 8),
            'storage_management.backup.enable_iran_replica' => false,
            'storage_management.backup.use_incremental_format' => false,
            'storage_management.backup.allow_unbudgeted_remote_full_source_reads' => false,
        ]);

        $source = StorageVolume::forceCreate([
            'drive_key' => 'foreign_primary',
            'name' => 'Unbudgeted foreign source',
            'driver' => 's3',
            'endpoint' => 'https://source.example.test',
            'bucket' => 'farast-source',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'Tigris backup target',
            'provider' => 'tigris',
            'driver' => 's3',
            'endpoint' => 'https://t3.storage.dev',
            'region' => 'auto',
            'bucket' => 'farast-backups',
            'credentials' => ['key' => 'test', 'secret' => 'test', 'region' => 'auto', 'use_path_style_endpoint' => false],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        StorageManagedFile::create([
            'storage_volume_id' => $source->id,
            'drive_key' => 'foreign_primary',
            'file_key' => 'products/private/source.pdf',
            'original_name' => 'source.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => str_repeat('a', 64),
            'classification' => 'critical',
            'status' => 'active',
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldNotReceive('downloadTo');
        $storage->shouldNotReceive('uploadBackup');

        try {
            (new StorageBackupService($storage))->runWeekly('weekly');
            $this->fail('A full backup must not read an unbudgeted remote S3 source.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('مبدأ S3 تأییدنشده', $exception->getMessage());
        }

        $this->assertDatabaseHas('storage_backup_runs', [
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'failed',
        ]);
    }

}
