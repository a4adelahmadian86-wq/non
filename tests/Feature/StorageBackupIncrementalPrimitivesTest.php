<?php

namespace Tests\Feature;

use App\Models\StorageBackupObject;
use App\Models\StorageVolume;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class StorageBackupIncrementalPrimitivesTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_content_addressed_backup_blob_is_uploaded_once_and_reused(): void
    {
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            $this->markTestSkipped('Sodium secretstream is not available in this PHP runtime.');
        }

        config([
            'storage_management.backup.encryption_key' => str_repeat('backup-key-', 8),
            'storage_management.backup.max_target_storage_bytes' => 4 * 1024 * 1024,
            'storage_management.backup.max_class_a_ops_per_month' => 8000,
            'storage_management.backup.max_class_b_ops_per_month' => 80000,
        ]);

        $volume = StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'Tigris test volume',
            'provider' => 'tigris',
            'driver' => 'local',
            'capacity_bytes' => 4 * 1024 * 1024,
            'used_bytes' => 0,
            'object_count' => 0,
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        $work = $this->temporaryDirectory();
        $source = $work.'/source.txt';
        file_put_contents($source, 'sensitive FARAST backup payload');

        $remoteObjects = [];
        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldReceive('volumeDisk')->andReturn('storage_volume:'.$volume->id);
        $storage->shouldReceive('exists')->once()->andReturnUsing(
            fn (string $disk, string $key): bool => array_key_exists($key, $remoteObjects)
        );
        $storage->shouldReceive('uploadBackup')->once()->andReturnUsing(
            function (StorageVolume $target, string $key, string $localPath) use (&$remoteObjects): void {
                $remoteObjects[$key] = file_get_contents($localPath);
            }
        );
        $storage->shouldReceive('size')->once()->andReturnUsing(
            fn (string $disk, string $key): int => strlen($remoteObjects[$key] ?? '')
        );

        $service = new StorageBackupService($storage);
        $method = new ReflectionMethod($service, 'storeBackupObject');
        $method->setAccessible(true);

        $first = $method->invoke($service, $volume, $source, str_repeat('backup-key-', 8), $work, ['source_kind' => 'test']);
        $second = $method->invoke($service, $volume, $source, str_repeat('backup-key-', 8), $work, ['source_kind' => 'test']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, count($remoteObjects));
        $this->assertSame(1, StorageBackupObject::query()->count());
        $this->assertSame(1, (int) $volume->fresh()->object_count);
        $this->assertStringNotContainsString('sensitive FARAST backup payload', reset($remoteObjects));
        $this->assertStringNotContainsString(hash('sha256', 'sensitive FARAST backup payload'), $first->object_key);
    }

    public function test_monthly_tigris_operation_budget_stops_before_overage(): void
    {
        config([
            'storage_management.backup.max_class_a_ops_per_month' => 1,
            'storage_management.backup.max_class_b_ops_per_month' => 10,
        ]);

        $volume = StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'Tigris budget fixture',
            'driver' => 'local',
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        $service = new StorageBackupService(Mockery::mock(StorageManager::class));
        $method = new ReflectionMethod($service, 'reserveBackupUsage');
        $method->setAccessible(true);
        $method->invoke($service, $volume, 'class_a');

        try {
            $method->invoke($service, $volume, 'class_a');
            $this->fail('The second Class A operation must be blocked before exceeding the configured budget.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('سقف امن عملیات ماهانه', $exception->getMessage());
        }

        $this->assertDatabaseHas('storage_backup_usage_periods', [
            'storage_volume_id' => $volume->id,
            'class_a_operations' => 1,
        ]);
    }

    public function test_monthly_iran_source_read_budget_is_reserved_before_download(): void
    {
        config([
            'storage_management.backup.max_iran_ops_per_month' => 10,
            'storage_management.backup.max_iran_source_read_bytes_per_month' => 1024,
        ]);

        $volume = StorageVolume::forceCreate([
            'drive_key' => 'iran_primary',
            'name' => 'Iran source budget fixture',
            'driver' => 'local',
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        $service = new StorageBackupService(Mockery::mock(StorageManager::class));
        $method = new ReflectionMethod($service, 'reserveBackupUsage');
        $method->setAccessible(true);
        $method->invoke($service, $volume, 'class_b', 1, 1024);

        try {
            $method->invoke($service, $volume, 'class_b', 1, 1);
            $this->fail('Iran source bytes must be blocked before exceeding the monthly budget.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('سقف امن ترافیک خواندن ماهانه', $exception->getMessage());
        }

        $this->assertDatabaseHas('storage_backup_usage_periods', [
            'storage_volume_id' => $volume->id,
            'source_read_bytes' => 1024,
        ]);
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/farast-backup-incremental-'.bin2hex(random_bytes(8));
        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create temporary test directory.');
        }
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
