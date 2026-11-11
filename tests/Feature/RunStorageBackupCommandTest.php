<?php

namespace Tests\Feature;

use App\Jobs\RunStorageBackupJob;
use App\Models\StorageBackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunStorageBackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_backup_request_uses_the_same_dispatch_lock_as_the_scheduler(): void
    {
        config(['storage_management.backup.encryption_key' => 'test-backup-key']);

        StorageVolume::forceCreate([
            'drive_key' => 'foreign_backup',
            'name' => 'Backup fixture',
            'driver' => 's3',
            'endpoint' => 'https://storage.example.test',
            'bucket' => 'farast-backups',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'is_active' => true,
            'status' => 'healthy',
            'priority' => 1,
        ]);

        $lock = Cache::lock('farast:backup:dispatch', 30);
        $this->assertTrue($lock->get());

        try {
            try {
                app(AdminStorageController::class)->requestBackup();
                $this->fail('The manual request must not bypass the scheduler dispatch lock.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('storage_backup_runs', 0);
    }

    public function test_weekly_backup_command_dispatches_to_the_dedicated_backups_queue(): void
    {
        Queue::fake();

        $this->artisan('storage:backup-weekly')->assertExitCode(0);

        Queue::assertPushedOn('backups', RunStorageBackupJob::class);

        $this->assertDatabaseHas('storage_backup_runs', [
            'source_name' => 'scheduler_request',
            'status' => 'queued',
        ]);
    }

    public function test_weekly_backup_command_does_not_dispatch_a_duplicate_active_request(): void
    {
        StorageBackupRun::create([
            'backup_type' => 'manual',
            'source_name' => 'manual_request',
            'status' => 'queued',
            'metadata' => ['request_only' => true],
        ]);
        Queue::fake();

        $this->artisan('storage:backup-weekly')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(
            1,
            StorageBackupRun::query()
                ->whereIn('source_name', ['scheduler_request', 'manual_request'])
                ->whereIn('status', ['queued', 'running'])
                ->count()
        );
    }

    public function test_weekly_backup_command_recovers_a_worker_request_abandoned_beyond_its_timeout(): void
    {
        $abandoned = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'scheduler_request',
            'status' => 'running',
            'started_at' => now()->subHours(6),
            'metadata' => ['request_only' => true],
        ]);
        $queuedTooLong = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'scheduler_request',
            'status' => 'queued',
            'metadata' => ['request_only' => true],
        ]);
        $queuedTooLong->forceFill(['created_at' => now()->subDays(2)])->save();

        $staleSnapshotLock = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'snapshot_lock',
            'status' => 'running',
            'started_at' => now()->subHours(6),
            'metadata' => ['snapshot' => true],
        ]);
        $staleApplicationRun = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'running',
            'started_at' => now()->subHours(6),
            'metadata' => [],
        ]);
        Queue::fake();

        $this->artisan('storage:backup-weekly')->assertExitCode(0);

        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $abandoned->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $queuedTooLong->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $staleSnapshotLock->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $staleApplicationRun->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('storage_backup_runs', [
            'source_name' => 'scheduler_request',
            'status' => 'queued',
        ]);
        Queue::assertPushedOn('backups', RunStorageBackupJob::class);
    }
}
