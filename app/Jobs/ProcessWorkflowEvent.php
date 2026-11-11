<?php

namespace App\Jobs;

use App\Services\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessWorkflowEvent implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 300;
    public int $uniqueFor = 1800;
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('automation');
    }

    public function uniqueId(): string
    {
        return 'farast-workflow-event:'.$this->eventId;
    }

    public function handle(WorkflowEngine $engine): void
    {
        $engine->processQueuedEvent($this->eventId);
    }

    public function failed(Throwable $exception): void
    {
        if (! Schema::hasTable('farast_workflow_events')) {
            return;
        }

        DB::table('farast_workflow_events')
            ->where('id', $this->eventId)
            ->whereNotIn('status', ['completed', 'paused'])
            ->update([
                'status' => 'failed',
                'attempts' => 5,
                'failed_at' => now(),
                'error_summary' => mb_substr($exception->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
    }
}
