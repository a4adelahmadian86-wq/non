<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Services\WorkflowEngine;

Schedule::command('farast:purge-files')->daily();
Schedule::command('farast:prune-user-files')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('farast:prune-product-ingestions --limit=200')->hourly()->withoutOverlapping();

// Process document ingestion and the default queue on cron-only/shared hosting.
// The worker exits when idle and is protected from overlapping scheduler launches.
Schedule::command('queue:work --queue=documents,default --stop-when-empty --max-time=900 --timeout=900 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->when(fn () => (bool) config('queue.scheduled_worker', true));

// پایش SLA پشتیبانی — هر ۱۵ دقیقه
Schedule::command('support:check-sla')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(fn () => config('support.monitor_enabled', true));


// انتشار اعلان‌هایی که زمان شروع‌شان رسیده و هنوز broadcast نشده‌اند
Schedule::command('announcements:broadcast-due')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Retry failed workflow steps without replaying steps that already completed.
Artisan::command('farast:workflows:retry {--limit=50}', function (WorkflowEngine $engine) {
    $completed = $engine->retryFailedEvents((int) $this->option('limit'));
    $this->info("Completed {$completed} retried workflow step(s).");
})->purpose('Retry failed FARAST workflow event steps');

Schedule::command('farast:workflows:retry --limit=50')
    ->everyMinute()
    ->withoutOverlapping();

// Move legacy support attachments out of the public web root in bounded batches.
Schedule::command('farast:secure-ticket-attachments --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
