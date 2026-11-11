<?php

namespace Tests\Feature;

use App\Jobs\RunStorageBackupJob;
use App\Models\StorageBackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackendCoreDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_waits_for_the_enclosing_transaction_to_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    public function test_storage_backups_use_the_first_party_database_queue(): void
    {
        $this->assertSame('database', config('queue.connections.backups.driver'));
        $this->assertSame('backups', config('queue.connections.backups.queue'));
        // CI explicitly selects sync; production defaults to database when no driver is configured.
        $this->assertSame(env('QUEUE_CONNECTION', 'database'), config('queue.default'));
    }

    public function test_weekly_backup_command_dispatches_to_the_dedicated_backups_queue(): void
    {
        Queue::fake();

        $this->artisan('storage:backup-weekly')->assertExitCode(0);

        $request = StorageBackupRun::query()
            ->where('source_name', 'scheduled_request')
            ->where('status', 'queued')
            ->firstOrFail();

        Queue::assertPushed(RunStorageBackupJob::class, function (RunStorageBackupJob $job) use ($request): bool {
            return $job->requestRunId === $request->id
                && $job->connection === 'backups'
                && $job->queue === 'backups';
        });
    }

    public function test_scheduled_workers_are_enabled_by_default_for_cron_only_hosts(): void
    {
        $this->assertTrue((bool) config('queue.scheduled_worker'));
    }

    public function test_default_logging_level_keeps_request_observability_events_visible(): void
    {
        $this->assertSame('info', config('logging.channels.daily.level'));
        $this->assertSame('info', config('logging.channels.stderr.level'));
    }
}
