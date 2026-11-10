<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (StorageVolume::query()->pluck('id') as $id) File::deleteDirectory(storage_path('app/storage-volumes/'.$id));
        parent::tearDown();
    }

    public function test_critical_uploads_only_use_iran_primary_volume(): void
    {
        $foreign = StorageVolume::create([
            'drive_key' => 'foreign_primary', 'name' => 'Foreign', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $iran = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 100,
        ]);

        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('critical.txt', 'iran-only'),
            StorageManager::CRITICAL,
            'test'
        );

        $this->assertSame($iran->id, $stored['volume_id']);
        $this->assertNotSame($foreign->id, $stored['volume_id']);
        $this->assertSame('iran-only', app(StorageManager::class)->get($stored['disk'], $stored['path']));
    }

    public function test_non_critical_uploads_use_foreign_volume(): void
    {
        $foreign = StorageVolume::create([
            'drive_key' => 'foreign_primary', 'name' => 'Foreign', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('editor.txt', 'foreign-preferred'),
            StorageManager::NON_CRITICAL,
            'editor'
        );

        $this->assertSame($foreign->id, $stored['volume_id']);
        $this->assertSame('foreign-preferred', app(StorageManager::class)->get($stored['disk'], $stored['path']));
    }

    public function test_uploads_are_staged_locally_when_no_remote_target_is_healthy(): void
    {
        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('staged.txt', 'keep-me'),
            StorageManager::NON_CRITICAL,
            'test'
        );

        $this->assertNull($stored['volume_id']);
        $this->assertSame('private', $stored['disk']);
        $this->assertSame('pending_transfer', $stored['status']);
        $this->assertTrue(app(StorageManager::class)->exists($stored['disk'], $stored['path']));
        $this->assertDatabaseHas('storage_managed_files', [
            'id' => $stored['managed_file_id'],
            'status' => 'pending_transfer',
            'storage_volume_id' => null,
        ]);
    }

    public function test_managed_deletes_are_deferred_until_backup_snapshot_finishes(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $storage = app(StorageManager::class);
        $stored = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('deferred.txt', 'keep-until-snapshot'),
            StorageManager::CRITICAL,
            'test'
        );
        $lock = StorageBackupRun::create([
            'backup_type' => 'weekly', 'source_name' => 'snapshot_lock',
            'status' => 'running', 'started_at' => now(),
        ]);

        $storage->delete($stored['disk'], $stored['path']);
        $this->assertTrue($storage->exists($stored['disk'], $stored['path']));
        $this->assertDatabaseHas('storage_managed_files', ['id' => $stored['managed_file_id'], 'status' => 'delete_pending']);

        $lock->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame(1, $storage->cleanupDeferredDeletes());
        $this->assertFalse($storage->exists($stored['disk'], $stored['path']));
        $volume->refresh();
        $this->assertSame(0, $volume->used_bytes);
        $this->assertSame(0, $volume->object_count);
    }

    public function test_deleting_a_staged_file_does_not_require_a_volume_relation(): void
    {
        $storage = app(StorageManager::class);
        $stored = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('staged-delete.txt', 'staged'),
            StorageManager::NON_CRITICAL,
            'test'
        );

        $storage->delete($stored['disk'], $stored['path']);
        $this->assertFalse($storage->exists($stored['disk'], $stored['path']));
        $this->assertDatabaseHas('storage_managed_files', ['id' => $stored['managed_file_id'], 'status' => 'deleted']);
    }

    public function test_deleting_a_managed_file_releases_volume_usage_and_object_count(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $storage = app(StorageManager::class);
        $stored = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('delete.txt', 'delete-me'),
            StorageManager::CRITICAL,
            'test'
        );

        $volume->refresh();
        $this->assertSame(9, $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);

        $storage->delete($stored['disk'], $stored['path']);
        $volume->refresh();

        $this->assertSame(0, $volume->used_bytes);
        $this->assertSame(0, $volume->object_count);
        $this->assertDatabaseHas('storage_managed_files', ['id' => $stored['managed_file_id'], 'status' => 'deleted']);
    }
    public function test_upload_fails_over_to_the_next_healthy_volume_in_the_same_drive(): void
    {
        $failed = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Unavailable S3', 'driver' => 's3',
            'endpoint' => 'https://storage.invalid.example', 'bucket' => 'bucket',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $iran = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran fallback', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 2,
        ]);

        $adapter = \\Mockery::mock(\\App\\Services\\Storage\\S3CompatibleAdapter::class);
        $adapter->shouldReceive('putFile')->once()->andThrow(new \\RuntimeException('provider unavailable'));
        $adapter->shouldReceive('delete')->zeroOrMoreTimes();
        app()->instance(\\App\\Services\\Storage\\S3CompatibleAdapter::class, $adapter);
        app()->forgetInstance(StorageManager::class);

        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('critical-failover.txt', 'keep-in-iran'),
            StorageManager::CRITICAL,
            'test'
        );

        $this->assertSame($iran->id, $stored['volume_id']);
        $this->assertNotSame($failed->id, $stored['volume_id']);
        $this->assertSame('error', $failed->fresh()->status);
        $this->assertSame('keep-in-iran', app(StorageManager::class)->get($stored['disk'], $stored['path']));
    }

}