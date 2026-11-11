<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackendCoreDefaultsTest extends TestCase
{
    public function test_database_queue_waits_for_the_enclosing_transaction_to_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    public function test_storage_backups_use_the_first_party_database_queue(): void
    {
        $this->assertSame('database', config('queue.connections.backups.driver'));
        $this->assertSame('backups', config('queue.connections.backups.queue'));
        // CI deliberately uses the synchronous driver so unrelated jobs do not run during the suite.
        $this->assertSame(env('QUEUE_CONNECTION', 'database'), config('queue.default'));
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
