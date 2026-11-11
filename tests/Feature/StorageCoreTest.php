<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Services\Storage\StorageManager;
use App\Services\Storage\StorageTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

        $adapter = \Mockery::mock(\App\Services\Storage\S3CompatibleAdapter::class);
        $adapter->shouldReceive('putFile')->once()->andThrow(new \RuntimeException('provider unavailable'));
        $adapter->shouldReceive('delete')->zeroOrMoreTimes();
        app()->instance(\App\Services\Storage\S3CompatibleAdapter::class, $adapter);
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

    public function test_public_managed_assets_are_served_without_exposing_private_files(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pV8AAAAASUVORK5CYII=');
        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('public.png', $png),
            StorageManager::CRITICAL,
            'test-public-asset',
            null,
            null,
            null,
            'public'
        );

        $this->get('/storage/managed/'.$stored['managed_file_id'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent($png);

        $private = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('private.png', $png),
            StorageManager::CRITICAL,
            'test-private-asset'
        );

        $this->get('/storage/managed/'.$private['managed_file_id'])->assertNotFound();
        $storage = app(StorageManager::class);
        $storage->delete($stored['disk'], $stored['path']);
        $storage->delete($private['disk'], $private['path']);
    }


    public function test_rebinding_a_restored_remote_file_cleans_old_object_after_successful_transfer(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran restore source', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $stored = app(StorageManager::class)->storeUploadedFile(
            UploadedFile::fake()->createWithContent('restore.txt', 'restore-content'),
            StorageManager::CRITICAL,
            'restore-test'
        );
        $stagingPath = 'restore-recovery/'.$stored['managed_file_id'].'/restore.txt';
        Storage::disk('private')->put($stagingPath, 'restore-content');

        $managed = StorageManagedFile::findOrFail($stored['managed_file_id']);
        app(StorageTransferService::class)->rebindToStaging($managed, $stagingPath);

        $volume->refresh();
        $managed->refresh();
        $this->assertSame((int) $stored['size_bytes'], $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
        $this->assertNull($managed->storage_volume_id);
        $this->assertSame('pending_transfer', $managed->status);
        $this->assertSame($stagingPath, $managed->file_key);
        $this->assertSame('private', data_get($managed->metadata, 'disk'));
        $oldKey = (string) data_get($managed->metadata, 'orphaned_source.file_key');
        $this->assertNotSame('', $oldKey);
        $this->assertTrue(app(StorageManager::class)->exists('storage_volume:'.$volume->id, $oldKey));

        $this->assertSame(1, app(StorageTransferService::class)->transferPending());
        $managed->refresh();
        $volume->refresh();
        $this->assertSame('active', $managed->status);
        $this->assertSame($volume->id, $managed->storage_volume_id);
        $this->assertSame((int) $stored['size_bytes'], $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
        $this->assertNull(data_get($managed->metadata, 'orphaned_source'));
        $this->assertFalse(app(StorageManager::class)->exists('storage_volume:'.$volume->id, $oldKey));
        $this->assertTrue(app(StorageManager::class)->exists($managed->metadata['disk'], $managed->file_key));
    }

    public function test_staged_transfer_is_claimed_once_and_updates_inventory_once(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'foreign_primary', 'name' => 'Foreign transfer target', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);
        $key = 'transfer-tests/one-file.txt';
        $contents = 'exactly-once-transfer';
        Storage::disk('private')->put($key, $contents);
        $managed = StorageManagedFile::create([
            'storage_volume_id' => null,
            'drive_key' => 'foreign_primary',
            'file_key' => $key,
            'file_key_hash' => hash('sha256', $key),
            'original_name' => 'one-file.txt',
            'mime' => 'text/plain',
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'classification' => 'non_critical',
            'source_module' => 'transfer-test',
            'visibility' => 'private',
            'status' => 'pending_transfer',
            'metadata' => [
                'disk' => 'private',
                'staging_disk' => 'private',
                'target_drive' => 'foreign_primary',
                'reference_pending' => false,
            ],
        ]);

        $transfer = app(StorageTransferService::class);
        $this->assertSame(1, $transfer->transferPending());
        $managed->refresh();
        $volume->refresh();

        $this->assertSame('active', $managed->status);
        $this->assertSame($volume->id, $managed->storage_volume_id);
        $this->assertSame('storage_volume:'.$volume->id, data_get($managed->metadata, 'disk'));
        $this->assertSame(strlen($contents), $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
        $this->assertFalse(Storage::disk('private')->exists($key));
        $this->assertSame(0, $transfer->transferPending());

        $volume->refresh();
        $this->assertSame(strlen($contents), $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
    }

    public function test_storage_volume_paths_reject_parent_directory_segments(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Iran path guard', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(StorageManager::class)->exists('storage_volume:'.$volume->id, '../outside.txt');
    }


    public function test_empty_volume_can_be_deleted_after_historical_file_rows_are_detached(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => false,
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Empty historical volume',
            'driver' => 'local',
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => false,
            'last_checked_at' => now(),
            'priority' => 1,
        ]);
        StorageManagedFile::create([
            'storage_volume_id' => $volume->id,
            'drive_key' => 'iran_primary',
            'file_key' => 'historical/deleted-file.txt',
            'file_key_hash' => hash('sha256', 'historical/deleted-file.txt'),
            'original_name' => 'deleted-file.txt',
            'mime' => 'text/plain',
            'size_bytes' => 10,
            'checksum' => hash('sha256', 'deleted-file'),
            'classification' => 'critical',
            'source_module' => 'storage-test',
            'visibility' => 'private',
            'status' => 'deleted',
            'metadata' => ['disk' => 'storage_volume:'.$volume->id],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.storage.destroy', $volume))
            ->assertRedirect(route('admin.storage.index'));

        $this->assertDatabaseMissing('storage_volumes', ['id' => $volume->id]);
        $this->assertDatabaseHas('storage_managed_files', [
            'file_key' => 'historical/deleted-file.txt',
            'storage_volume_id' => null,
            'status' => 'deleted',
        ]);
    }

}
