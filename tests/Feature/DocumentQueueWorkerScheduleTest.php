<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentQueueWorkerScheduleTest extends TestCase
{
    public function test_document_queue_worker_is_registered_in_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('queue:work --queue=documents,documents-audio,default --stop-when-empty')
            ->assertExitCode(0);
    }
}
