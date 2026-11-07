<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackendCoreDefaultsTest extends TestCase
{
    public function test_database_queue_waits_for_the_enclosing_transaction_to_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    public function test_default_logging_level_keeps_request_observability_events_visible(): void
    {
        $this->assertSame('info', config('logging.channels.daily.level'));
        $this->assertSame('info', config('logging.channels.stderr.level'));
    }
}
