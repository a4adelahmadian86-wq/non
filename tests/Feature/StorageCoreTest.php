<?php

namespace Tests\Feature;

use App\Models\StorageManagedFile;
use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Models\StorageVolumeReservation;
use App\Models\User;
use App\Services\Storage\StorageManager;
use App\Services\Storage\S3CompatibleAdapter;
use App\Services\Storage\StorageTransferService;
use App\Services\StorageConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
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

    public function test_capacity_reservations_prevent_uploads_from_overcommitting_a_volume(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Reserved capacity volume', 'driver' => 'local',
            'capacity_bytes' => 10, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $storage = app(StorageManager::class);
        $reservation = $storage->reserveVolumeCapacity($volume, 7, 'test-upload', [
            'object_key' => 'reservations/first.bin',
        ]);
        $this->assertNotNull($reservation);
        $this->assertSame(3, $volume->fresh()->free_bytes);
        $this->assertNull($storage->reserveVolumeCapacity($volume, 4, 'concurrent-upload', [
            'object_key' => 'reservations/second.bin',
        ]));

        $stagedContents = '1234';
        $staged = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('capacity-blocked.txt', $stagedContents),
            StorageManager::CRITICAL,
            'capacity-test'
        );
        $this->assertNull($staged['volume_id']);
        $this->assertSame('pending_transfer', $staged['status']);

        $storage->releaseVolumeCapacityReservation($reservation);
        $stored = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('capacity-available.txt', $stagedContents),
            StorageManager::CRITICAL,
            'capacity-test'
        );

        $this->assertSame($volume->id, $stored['volume_id']);
        $volume->refresh();
        $this->assertSame(strlen($stagedContents), $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
        $this->assertSame(6, $volume->free_bytes);
        $this->assertDatabaseCount('storage_volume_reservations', 0);
    }

    public function test_backup_upload_reserves_capacity_until_checksum_verified_run_is_committed(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'foreign_backup',
            'name' => 'Backup reservation volume',
            'driver' => 'local',
            'capacity_bytes' => 1048576,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);
        $run = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'running',
            'started_at' => now(),
            'size_bytes' => 0,
            'checksum' => hash('sha256', 'backup-payload'),
        ]);
        $temporary = tempnam(sys_get_temp_dir(), 'farast-backup-reservation-test-');
        $this->assertIsString($temporary);
        $payload = 'backup-payload';
        file_put_contents($temporary, $payload);

        try {
            $storage = app(StorageManager::class);
            $key = 'weekly/test/farast-reservation.zip';
            $reservation = $storage->uploadBackup($volume, $key, $temporary, (int) $run->id);

            $this->assertTrue($storage->exists($storage->volumeDisk($volume), $key));
            $this->assertDatabaseHas('storage_volume_reservations', [
                'reservation_key' => $reservation,
                'storage_volume_id' => $volume->id,
                'reserved_bytes' => strlen($payload),
                'purpose' => 'backup',
                'status' => 'active',
            ]);
            $this->assertSame(0, $volume->fresh()->used_bytes);
            $this->assertSame(0, $volume->fresh()->object_count);

            $storage->commitVolumeCapacityReservation($reservation);
            $run->update(['status' => 'completed', 'object_key' => $key, 'completed_at' => now()]);
            $volume->refresh();
            $this->assertSame(strlen($payload), $volume->used_bytes);
            $this->assertSame(1, $volume->object_count);
            $this->assertDatabaseCount('storage_volume_reservations', 0);
        } finally {
            @unlink($temporary);
        }
    }

    public function test_expired_capacity_reservation_removes_partial_object_before_releasing_capacity(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Expired reservation volume', 'driver' => 'local',
            'capacity_bytes' => 1024, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $storage = app(StorageManager::class);
        $backupRun = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'running',
            'started_at' => now(),
            'size_bytes' => 16,
            'checksum' => hash('sha256', 'partial-upload'),
        ]);
        $key = 'reservations/abandoned-upload.bin';
        $reservation = $storage->reserveVolumeCapacity($volume, 16, 'backup', [
            'object_key' => $key,
            'backup_run_id' => $backupRun->id,
        ]);
        $this->assertNotNull($reservation);

        $absolute = storage_path('app/storage-volumes/'.$volume->id.'/'.$key);
        File::ensureDirectoryExists(dirname($absolute));
        file_put_contents($absolute, 'partial-upload');
        StorageVolumeReservation::query()
            ->where('reservation_key', $reservation)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, $storage->cleanupExpiredVolumeReservations());
        $this->assertFileDoesNotExist($absolute);
        $this->assertDatabaseCount('storage_volume_reservations', 0);
        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $backupRun->id,
            'status' => 'failed',
            'object_key' => $key,
        ]);
    }

    public function test_generated_contents_use_the_central_managed_storage_pipeline(): void
    {
        StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Generated content volume', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $contents = '%PDF-1.4 generated preview payload';
        $storage = app(StorageManager::class);
        $stored = $storage->storeContents(
            $contents,
            'generated-preview.pdf',
            'application/pdf',
            StorageManager::CRITICAL,
            'store-product-preview'
        );

        $this->assertSame($contents, $storage->get($stored['disk'], $stored['path']));
        $this->assertSame(strlen($contents), $stored['size_bytes']);
        $this->assertSame(hash('sha256', $contents), $stored['sha256']);
        $this->assertDatabaseHas('storage_managed_files', [
            'id' => $stored['managed_file_id'],
            'status' => 'active',
            'checksum' => hash('sha256', $contents),
        ]);
    }

    public function test_size_reads_local_volume_and_private_staging_objects(): void
    {
        StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Local size volume', 'driver' => 'local',
            'capacity_bytes' => 10485760, 'used_bytes' => 0, 'object_count' => 0,
            'status' => 'healthy', 'is_active' => true, 'priority' => 1,
        ]);

        $storage = app(StorageManager::class);
        $localContents = 'measured-local-content';
        $local = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('local-size.txt', $localContents),
            StorageManager::CRITICAL,
            'size-test'
        );

        $this->assertSame(strlen($localContents), $storage->size($local['disk'], $local['path']));

        $stagedContents = 'measured-staging-content';
        $staged = $storage->storeUploadedFile(
            UploadedFile::fake()->createWithContent('staged-size.txt', $stagedContents),
            StorageManager::NON_CRITICAL,
            'size-test'
        );

        $this->assertNull($staged['volume_id']);
        $this->assertSame(strlen($stagedContents), $storage->size($staged['disk'], $staged['path']));
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


    public function test_volume_cannot_be_deleted_while_capacity_is_reserved(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => false,
        ]);
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Reserved volume',
            'driver' => 'local',
            'capacity_bytes' => 1024,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'last_checked_at' => now(),
            'priority' => 1,
        ]);

        $storage = app(StorageManager::class);
        $reservation = $storage->reserveVolumeCapacity($volume, 12, 'delete-guard-test', [
            'object_key' => 'reservations/protected.bin',
        ]);
        $this->assertNotNull($reservation);

        $this->actingAs($admin)
            ->delete(route('admin.storage.destroy', $volume))
            ->assertStatus(409);

        $this->assertDatabaseHas('storage_volumes', ['id' => $volume->id]);
        $storage->releaseVolumeCapacityReservation($reservation);

        $this->actingAs($admin)
            ->delete(route('admin.storage.destroy', $volume))
            ->assertRedirect(route('admin.storage.index'));

        $this->assertDatabaseMissing('storage_volumes', ['id' => $volume->id]);
    }

    public function test_transfer_failures_use_exponential_backoff_instead_of_hot_looping(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'foreign_primary',
            'name' => 'Temporarily unavailable transfer target',
            'driver' => 's3',
            'endpoint' => 'https://storage.example.invalid',
            'bucket' => 'farast',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'capacity_bytes' => 10485760,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $key = 'transfer-tests/retry-backoff.txt';
        $contents = 'retry-with-backoff';
        Storage::disk('private')->put($key, $contents);
        $file = StorageManagedFile::create([
            'storage_volume_id' => null,
            'drive_key' => 'foreign_primary',
            'file_key' => $key,
            'file_key_hash' => hash('sha256', $key),
            'original_name' => 'retry-backoff.txt',
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

        $adapter = Mockery::mock(S3CompatibleAdapter::class);
        $adapter->shouldReceive('putFile')->once()->andThrow(new RuntimeException('provider temporarily unavailable'));
        $adapter->shouldReceive('delete')->once();
        app()->instance(S3CompatibleAdapter::class, $adapter);
        app()->forgetInstance(StorageManager::class);
        app()->forgetInstance(StorageTransferService::class);

        $transfer = app(StorageTransferService::class);
        $this->assertSame(0, $transfer->transferPending());
        $file->refresh();

        $this->assertSame('transfer_error', $file->status);
        $this->assertGreaterThan(0, (int) data_get($file->metadata, 'transfer_attempts'));
        $this->assertTrue(Carbon::parse(data_get($file->metadata, 'next_transfer_at'))->isFuture());
        $this->assertSame(0, $transfer->transferPending());
        $this->assertDatabaseCount('storage_volume_reservations', 0);
    }

    public function test_local_inventory_ignores_and_reclaims_abandoned_write_artifacts(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary', 'name' => 'Local inventory', 'driver' => 'local',
            'used_bytes' => 0, 'object_count' => 0, 'status' => 'unverified',
            'is_active' => true, 'priority' => 1,
        ]);
        $root = storage_path('app/storage-volumes/'.$volume->id);
        File::ensureDirectoryExists($root.'/documents');
        file_put_contents($root.'/documents/real.txt', 'real');
        $abandoned = $root.'/.farast-upload-abandoned.tmp';
        file_put_contents($abandoned, 'partial-write');
        touch($abandoned, now()->subHours(2)->timestamp);

        app(StorageConnectionService::class)->verify($volume, true);
        $volume->refresh();

        $this->assertSame(4, $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);
        $this->assertFileDoesNotExist($abandoned);
    }

    public function test_failed_provider_delete_keeps_a_retryable_record_and_releases_inventory_only_after_success(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Recoverable delete volume',
            'driver' => 's3',
            'endpoint' => 'https://storage.example.invalid',
            'bucket' => 'farast',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'capacity_bytes' => 10485760,
            'used_bytes' => 12,
            'object_count' => 1,
            'status' => 'healthy',
            'is_active' => true,
            'priority' => 1,
        ]);

        $file = StorageManagedFile::create([
            'storage_volume_id' => $volume->id,
            'drive_key' => 'iran_primary',
            'file_key' => 'recoverable/delete-me.txt',
            'file_key_hash' => hash('sha256', 'recoverable/delete-me.txt'),
            'original_name' => 'delete-me.txt',
            'mime' => 'text/plain',
            'size_bytes' => 12,
            'checksum' => hash('sha256', 'delete-me'),
            'classification' => 'critical',
            'source_module' => 'storage-test',
            'visibility' => 'private',
            'status' => 'active',
            'metadata' => ['disk' => 'storage_volume:'.$volume->id],
        ]);

        $failedAdapter = \Mockery::mock(\App\Services\Storage\S3CompatibleAdapter::class);
        $failedAdapter->shouldReceive('delete')->once()->andThrow(new \RuntimeException('provider temporarily unavailable'));
        app()->instance(\App\Services\Storage\S3CompatibleAdapter::class, $failedAdapter);
        app()->forgetInstance(StorageManager::class);

        try {
            app(StorageManager::class)->delete('storage_volume:'.$volume->id, $file->file_key);
            $this->fail('The provider failure should be surfaced to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider temporarily unavailable', $exception->getMessage());
        }

        $file->refresh();
        $volume->refresh();
        $this->assertSame('delete_pending', $file->status);
        $this->assertNotNull(data_get($file->metadata, 'delete_requested_at'));
        $this->assertSame(12, $volume->used_bytes);
        $this->assertSame(1, $volume->object_count);

        $retryAdapter = \Mockery::mock(\App\Services\Storage\S3CompatibleAdapter::class);
        $retryAdapter->shouldReceive('delete')->once();
        app()->instance(\App\Services\Storage\S3CompatibleAdapter::class, $retryAdapter);
        app()->forgetInstance(StorageManager::class);

        $this->assertSame(1, app(StorageManager::class)->cleanupDeferredDeletes());
        $file->refresh();
        $volume->refresh();

        $this->assertSame('deleted', $file->status);
        $this->assertNull(data_get($file->metadata, 'delete_requested_at'));
        $this->assertSame(0, $volume->used_bytes);
        $this->assertSame(0, $volume->object_count);
    }

}
