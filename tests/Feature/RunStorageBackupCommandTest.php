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
            'backup_type' => 'weekly',
            'source_name' => 'scheduler_request',
            'status' => 'queued',
            'metadata' => ['request_only' => true],
        ]);
        Queue::fake();

        $this->artisan('storage:backup-weekly')->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame(
            1,
            StorageBackupRun::query()
                ->where('source_name', 'scheduler_request')
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
            'source_name' => 'scheduler_request',
            'status' => 'queued',
        ]);
        Queue::assertPushedOn('backups', RunStorageBackupJob::class);
    }
}
