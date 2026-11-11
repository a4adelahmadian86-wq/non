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
}
