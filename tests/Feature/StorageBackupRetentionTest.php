<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class StorageBackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_latest_verified_backup_is_retained_and_counters_are_reconciled(): void
    {
        config(['storage_management.backup.keep_latest' => 1]);

        $volume = StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'External backup',
            'driver' => 'local',
            'endpoint' => null,
            'bucket' => null,
            'credentials' => [],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
            'capacity_bytes' => 10000,
            'used_bytes' => 3000,
            'object_count' => 3,
        ]);

        $oldest = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'weekly/oldest.zip',
            'size_bytes' => 1000,
            'checksum' => str_repeat('a', 64),
            'completed_at' => now()->subDays(14),
        ]);

        $older = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'weekly/older.zip',
            'size_bytes' => 1000,
            'checksum' => str_repeat('b', 64),
            'completed_at' => now()->subDays(7),
        ]);

        $current = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'weekly/current.zip',
            'size_bytes' => 1000,
            'checksum' => str_repeat('c', 64),
            'completed_at' => now(),
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldReceive('volumeDisk')->twice()->with(Mockery::type(StorageVolume::class))->andReturn('storage_volume:'.$volume->id);
        $storage->shouldReceive('delete')->twice()->with('storage_volume:'.$volume->id, Mockery::type('string'))->andReturnNull();

        $service = new StorageBackupService($storage);
        $method = new ReflectionMethod(StorageBackupService::class, 'pruneOldBackups');
        $method->setAccessible(true);
        $method->invoke($service, $volume, $current);

        $this->assertSame('pruned', $oldest->fresh()->status);
        $this->assertNull($oldest->fresh()->object_key);
        $this->assertSame('weekly/oldest.zip', data_get($oldest->fresh()->metadata, 'pruned_object_key'));
        $this->assertSame('pruned', $older->fresh()->status);
        $this->assertSame('completed', $current->fresh()->status);
        $this->assertSame('weekly/current.zip', $current->fresh()->object_key);
        $this->assertSame(1000, (int) $volume->fresh()->used_bytes);
        $this->assertSame(1, (int) $volume->fresh()->object_count);
    }

    public function test_failed_remote_prune_keeps_counters_and_marks_cleanup_pending(): void
    {
        config(['storage_management.backup.keep_latest' => 1]);

        $volume = StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'External backup',
            'driver' => 'local',
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
            'capacity_bytes' => 10000,
            'used_bytes' => 2000,
            'object_count' => 2,
        ]);

        $old = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'weekly/old.zip',
            'size_bytes' => 1000,
            'checksum' => str_repeat('d', 64),
            'completed_at' => now()->subDays(7),
        ]);

        $current = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'weekly/current.zip',
            'size_bytes' => 1000,
            'checksum' => str_repeat('e', 64),
            'completed_at' => now(),
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldReceive('volumeDisk')->once()->andReturn('storage_volume:'.$volume->id);
        $storage->shouldReceive('delete')->once()->andThrow(new \RuntimeException('temporary delete failure'));

        $service = new StorageBackupService($storage);
        $method = new ReflectionMethod(StorageBackupService::class, 'pruneOldBackups');
        $method->setAccessible(true);
        $method->invoke($service, $volume, $current);

        $this->assertSame('completed', $old->fresh()->status);
        $this->assertSame('weekly/old.zip', $old->fresh()->object_key);
        $this->assertTrue((bool) data_get($old->fresh()->metadata, 'retention_cleanup_pending'));
        $this->assertSame(2000, (int) $volume->fresh()->used_bytes);
        $this->assertSame(2, (int) $volume->fresh()->object_count);
    }

}
