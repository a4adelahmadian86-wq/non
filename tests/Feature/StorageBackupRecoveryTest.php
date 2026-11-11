<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Models\User;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageBackupRecoveryTest extends TestCase
{
    private const CONNECTION = 'backup_recovery_test';

    private string $sourceDatabase;
    private string $targetDatabase;
    private int $volumeId = 910001;
    private ?string $bootstrapArchive = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = tempnam(sys_get_temp_dir(), 'farast-backup-source-');
        $this->targetDatabase = tempnam(sys_get_temp_dir(), 'farast-backup-target-');
    }

    protected function tearDown(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable) {
            // The application may not have entered maintenance mode.
        }

        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);

        File::deleteDirectory(storage_path('app/storage-volumes/'.$this->volumeId));
        if ($this->bootstrapArchive) @unlink($this->bootstrapArchive);
        @unlink(storage_path('app/private/backup-recovery-fixture.txt'));
        @unlink(storage_path('app/private/backup-recovery-target-only.txt'));
        @unlink(storage_path('app/private/bootstrap-recovery-fixture.txt'));
        @unlink(storage_path('app/private/bootstrap-target-only.txt'));

        foreach ([$this->sourceDatabase, $this->targetDatabase] as $path) {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }

        parent::tearDown();
    }

    public function test_encrypted_backup_verifies_and_restores_into_an_isolated_database(): void
    {
        $this->useDatabase($this->sourceDatabase);
        config(['storage_management.backup.encryption_key' => 'farast-test-backup-key-2026']);

        User::create([
            'name' => 'Backup source user',
            'mobile' => '09110000001',
            'password' => 'source-password',
        ]);

        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/backup-recovery-fixture.txt'), 'snapshot-private-content');

        // The scheduled job marks this request running before it takes the snapshot.
        // Restore must close that in-flight request or the next weekly backup is blocked.
        StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'scheduler_request',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['request_only' => true],
        ]);

        StorageBackupRun::create([
            'backup_type' => 'manual',
            'source_name' => 'manual_request',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['request_only' => true],
        ]);

        StorageVolume::forceCreate([
            'id' => $this->volumeId,
            'drive_key' => 'foreign_backup',
            'name' => 'Isolated recovery fixture',
            'driver' => 'local',
            'capacity_bytes' => 100 * 1024 * 1024,
            'used_bytes' => 0,
            'object_count' => 0,
            'priority' => 1,
            'is_active' => true,
            'status' => 'healthy',
        ]);

        app()->forgetInstance(\App\Services\Storage\StorageManager::class);
        $results = app(StorageBackupService::class)->runWeekly();

        $this->assertCount(1, $results);
        $this->assertSame('completed', $results[0]['status']);

        $backupRun = StorageBackupRun::query()
            ->where('source_name', 'application')
            ->latest('id')
            ->first();
        $this->assertNotNull($backupRun, 'StorageBackupService did not create an application backup run.');
        $this->assertSame('completed', $backupRun->status, (string) $backupRun->error_message);

        $verifyExitCode = Artisan::call('storage:verify-backup', ['runId' => $backupRun->id]);
        $this->assertSame(0, $verifyExitCode, Artisan::output());

        $runId = (int) $backupRun->id;
        $objectKey = (string) $backupRun->object_key;
        $checksum = (string) $backupRun->checksum;
        $sizeBytes = (int) $backupRun->size_bytes;
        $startedAt = $backupRun->started_at;
        $completedAt = $backupRun->completed_at;
        $metadata = (array) $backupRun->metadata;

        $this->useDatabase($this->targetDatabase);

        User::create([
            'name' => 'Target-only user',
            'mobile' => '09110000002',
            'password' => 'target-password',
        ]);
        File::put(storage_path('app/private/backup-recovery-target-only.txt'), 'must-be-removed');

        StorageVolume::forceCreate([
            'id' => $this->volumeId,
            'drive_key' => 'foreign_backup',
            'name' => 'Isolated recovery fixture',
            'driver' => 'local',
            'capacity_bytes' => 100 * 1024 * 1024,
            'used_bytes' => 0,
            'object_count' => 0,
            'priority' => 1,
            'is_active' => true,
            'status' => 'healthy',
        ]);

        StorageBackupRun::forceCreate([
            'id' => $runId,
            'storage_volume_id' => $this->volumeId,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => $objectKey,
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'metadata' => $metadata,
        ]);

        app()->forgetInstance(\App\Services\Storage\StorageManager::class);
        $restoreExitCode = Artisan::call('storage:restore-backup', [
            'runId' => $runId,
            '--confirm' => true,
        ]);
        $restoreOutput = Artisan::output();
        Artisan::call('up');

        $this->assertSame(0, $restoreExitCode, $restoreOutput);
        $this->assertTrue(User::query()->where('mobile', '09110000001')->exists());
        $this->assertFalse(User::query()->where('mobile', '09110000002')->exists());
        $this->assertSame(
            'snapshot-private-content',
            File::get(storage_path('app/private/backup-recovery-fixture.txt'))
        );
        $this->assertFileDoesNotExist(storage_path('app/private/backup-recovery-target-only.txt'));

        $restoredRun = StorageBackupRun::query()->findOrFail($runId);
        $this->assertSame($checksum, $restoredRun->checksum);
        $this->assertNotEmpty(data_get($restoredRun->metadata, 'restored_at'));

        $this->assertSame(
            0,
            StorageBackupRun::query()
                ->where('source_name', 'snapshot_lock')
                ->where('status', 'running')
                ->count()
        );
        $this->assertSame(
            0,
            StorageBackupRun::query()
                ->where('source_name', 'scheduler_request')
                ->whereIn('status', ['queued', 'running'])
                ->count()
        );
        $this->assertNotEmpty(
            data_get(
                StorageBackupRun::query()->where('source_name', 'scheduler_request')->firstOrFail()->metadata,
                'restored_at'
            )
        );

        $this->assertSame(
            0,
            StorageBackupRun::query()
                ->where('source_name', 'manual_request')
                ->whereIn('status', ['queued', 'running'])
                ->count()
        );
        $this->assertNotEmpty(
            data_get(
                StorageBackupRun::query()->where('source_name', 'manual_request')->firstOrFail()->metadata,
                'restored_at'
            )
        );

        $restoredVolume = StorageVolume::query()->findOrFail($this->volumeId);
        $this->assertSame($sizeBytes, (int) $restoredVolume->used_bytes);
        $this->assertSame(1, (int) $restoredVolume->object_count);
        $this->assertFalse(app()->isDownForMaintenance());

    }

    public function test_local_archive_bootstrap_restore_works_without_backup_run_metadata(): void
    {
        $this->useDatabase($this->sourceDatabase);
        config(['storage_management.backup.encryption_key' => 'farast-test-backup-key-2026']);

        User::create([
            'name' => 'Bootstrap source user',
            'mobile' => '09110000011',
            'password' => 'source-password',
        ]);

        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/bootstrap-recovery-fixture.txt'), 'bootstrap-private-content');

        StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'scheduler_request',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['request_only' => true],
        ]);
        StorageBackupRun::create([
            'backup_type' => 'manual',
            'source_name' => 'manual_request',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['request_only' => true],
        ]);

        StorageVolume::forceCreate([
            'id' => $this->volumeId,
            'drive_key' => 'foreign_backup',
            'name' => 'Bootstrap recovery fixture',
            'driver' => 'local',
            'capacity_bytes' => 100 * 1024 * 1024,
            'used_bytes' => 0,
            'object_count' => 0,
            'priority' => 1,
            'is_active' => true,
            'status' => 'healthy',
        ]);

        app()->forgetInstance(StorageManager::class);
        app(StorageBackupService::class)->runWeekly();

        $backupRun = StorageBackupRun::query()
            ->where('source_name', 'application')
            ->where('status', 'completed')
            ->latest('id')
            ->firstOrFail();
        $volume = StorageVolume::query()->findOrFail($this->volumeId);
        $this->bootstrapArchive = tempnam(sys_get_temp_dir(), 'farast-bootstrap-archive-');
        $storage = app(StorageManager::class);
        $storage->downloadTo($storage->volumeDisk($volume), $backupRun->object_key, $this->bootstrapArchive);

        // A fresh, empty database simulates loss of the primary database and all
        // storage_backup_runs metadata. The local archive must be sufficient.
        $this->useDatabase($this->targetDatabase);
        User::create([
            'name' => 'Bootstrap target-only user',
            'mobile' => '09110000012',
            'password' => 'target-password',
        ]);
        File::put(storage_path('app/private/bootstrap-target-only.txt'), 'must-be-removed');
        app()->forgetInstance(StorageManager::class);

        $restoreExitCode = Artisan::call('storage:restore-backup', [
            '--archive' => $this->bootstrapArchive,
            '--confirm' => true,
        ]);
        $restoreOutput = Artisan::output();
        Artisan::call('up');

        $this->assertSame(0, $restoreExitCode, $restoreOutput);
        $this->assertTrue(User::query()->where('mobile', '09110000011')->exists());
        $this->assertFalse(User::query()->where('mobile', '09110000012')->exists());
        $this->assertSame(
            'bootstrap-private-content',
            File::get(storage_path('app/private/bootstrap-recovery-fixture.txt'))
        );
        $this->assertFileDoesNotExist(storage_path('app/private/bootstrap-target-only.txt'));

        $bootstrapRun = StorageBackupRun::query()
            ->where('source_name', 'bootstrap_restore')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('completed', $bootstrapRun->status);
        $this->assertTrue((bool) data_get($bootstrapRun->metadata, 'bootstrap'));
        $this->assertNotEmpty(data_get($bootstrapRun->metadata, 'archive_sha256'));
        $this->assertSame(
            0,
            StorageBackupRun::query()
                ->whereIn('source_name', ['snapshot_lock', 'scheduler_request', 'manual_request'])
                ->whereIn('status', ['queued', 'running'])
                ->count()
        );
        $this->assertTrue(StorageVolume::query()->whereKey($this->volumeId)->exists());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    private function useDatabase(string $path): void
    {
        $connection = config('database.connections.sqlite');
        $connection['database'] = $path;

        config([
            'database.connections.'.self::CONNECTION => $connection,
            'database.default' => self::CONNECTION,
        ]);

        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);

        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => self::CONNECTION,
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
