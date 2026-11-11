<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Models\User;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class StorageBackupRestoreIntegrationTest extends TestCase
{
    private string $databasePath;
    private string $originalDefault;
    private array $originalSqlite;
    private string $originalBackupKey;
    private ?int $volumeId = null;
    private ?string $workDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = (string) config('database.default');
        $this->originalSqlite = (array) config('database.connections.sqlite', []);
        $this->originalBackupKey = (string) config('storage_management.backup.encryption_key', '');
        $databasePath = tempnam(sys_get_temp_dir(), 'farast-restore-db-');
        if (! is_string($databasePath) || $databasePath === '') {
            throw new RuntimeException('ساخت دیتابیس موقت آزمون بازیابی ناموفق بود.');
        }
        $this->databasePath = $databasePath;

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable) {
        }

        DB::purge('sqlite');
        config([
            'database.default' => $this->originalDefault,
            'database.connections.sqlite' => $this->originalSqlite,
            'storage_management.backup.encryption_key' => $this->originalBackupKey,
        ]);
        DB::purge();

        if ($this->volumeId !== null) {
            File::deleteDirectory(storage_path('app/storage-volumes/'.$this->volumeId));
        }
        if ($this->workDirectory !== null) {
            File::deleteDirectory($this->workDirectory);
        }
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_encrypted_backup_restores_a_separate_sqlite_database_and_reconciles_inventory(): void
    {
        if (! class_exists(ZipArchive::class) || ! defined(ZipArchive::class.'::EM_AES_256')) {
            $this->markTestSkipped('ZipArchive AES-256 support is required for the restore integration test.');
        }

        $this->workDirectory = sys_get_temp_dir().'/farast-restore-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workDirectory);

        $volume = StorageVolume::create([
            'drive_key' => 'iran_backup',
            'name' => 'Restore integration volume',
            'driver' => 'local',
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);
        $this->volumeId = (int) $volume->id;

        $beforeSnapshot = User::factory()->create();
        $run = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'running',
            'started_at' => now(),
            'size_bytes' => 0,
            'checksum' => null,
        ]);

        DB::statement('PRAGMA wal_checkpoint(FULL)');
        DB::purge('sqlite');

        $snapshot = $this->workDirectory.'/database.sqlite';
        if (! copy($this->databasePath, $snapshot)) {
            throw new RuntimeException('ساخت snapshot پایگاه داده آزمون ناموفق بود.');
        }

        DB::connection('sqlite');
        $afterSnapshot = User::factory()->create();

        $password = 'restore-integration-test-key-2026';
        config(['storage_management.backup.encryption_key' => $password]);

        $archive = $this->workDirectory.'/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ساخت آرشیو رمزنگاری‌شده آزمون ناموفق بود.');
        }
        if (! $zip->addFile($snapshot, 'database/database.sql')
            || ! $zip->setEncryptionName('database/database.sql', ZipArchive::EM_AES_256, $password)
            || ! $zip->addFromString('manifest.json', json_encode(['database_driver' => 'sqlite'], JSON_THROW_ON_ERROR))
            || ! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password)) {
            $zip->close();
            throw new RuntimeException('رمزنگاری ورودی‌های آرشیو آزمون ناموفق بود.');
        }
        $zip->close();

        $checksum = hash_file('sha256', $archive);
        $size = filesize($archive);
        if (! is_string($checksum) || ! is_int($size)) {
            throw new RuntimeException('محاسبه Checksum یا اندازه آرشیو آزمون ناموفق بود.');
        }

        $storage = app(StorageManager::class);
        $objectKey = 'integration/restore/backup.zip';
        $reservation = $storage->uploadBackup($volume, $objectKey, $archive, (int) $run->id);
        $storage->commitVolumeCapacityReservation($reservation);
        $run->update([
            'status' => 'completed',
            'object_key' => $objectKey,
            'checksum' => $checksum,
            'size_bytes' => $size,
            'completed_at' => now(),
        ]);

        $exitCode = Artisan::call('storage:restore-backup', [
            'runId' => $run->id,
            '--confirm' => true,
        ]);
        $output = Artisan::output();
        Artisan::call('up');

        $this->assertSame(0, $exitCode, $output);
        $this->assertDatabaseHas('users', ['id' => $beforeSnapshot->id]);
        $this->assertDatabaseMissing('users', ['id' => $afterSnapshot->id]);
        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $run->id,
            'status' => 'completed',
            'object_key' => $objectKey,
            'checksum' => $checksum,
            'size_bytes' => $size,
        ]);
        $restoredVolume = StorageVolume::findOrFail($volume->id);
        $this->assertSame($size, $restoredVolume->used_bytes);
        $this->assertSame(1, $restoredVolume->object_count);
    }
}
