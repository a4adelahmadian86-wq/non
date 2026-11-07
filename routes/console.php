<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Services\WorkflowEngine;

Schedule::command('farast:purge-files')->daily();
Schedule::command('farast:prune-user-files')->dailyAt('03:20')->withoutOverlapping();

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
