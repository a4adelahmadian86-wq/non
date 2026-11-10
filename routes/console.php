<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\WorkflowEngine;
use App\Services\StoreCartAbandonmentService;

Schedule::command('farast:purge-files')->daily();
Schedule::command('farast:prune-user-files')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('farast:prune-product-ingestions --limit=200')->hourly()->withoutOverlapping();

// Recover abandoned document jobs only after the worker lease has safely expired.
Schedule::command('farast:recover-product-documents --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping(30);

// Process document ingestion and the default queue on cron-only/shared hosting.
// The worker exits when idle and is protected from overlapping scheduler launches.
Schedule::command('queue:work --queue=documents,documents-audio,default --stop-when-empty --max-time=900 --timeout=900 --tries=3')
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


Artisan::command('store:carts:process-abandoned {--limit=200}', function (StoreCartAbandonmentService $service) {
    $count = $service->process((int) $this->option('limit'));
    $this->info("Recorded {$count} consented abandoned-cart signal(s).");
})->purpose('Process consented abandoned store carts for smart discount marketing');

Schedule::command('store:carts:process-abandoned --limit=200')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Artisan::command('store:marketing:prune-signals {--days=90} {--limit=5000}', function () {
    if (! Schema::hasTable('farast_workflow_events')) {
        $this->info('Marketing signal storage is not installed; nothing to prune.');
        return 0;
    }

    $days = max(30, min(365, (int) $this->option('days')));
    $limit = max(100, min(20000, (int) $this->option('limit')));
    $ids = DB::table('farast_workflow_events')
        ->where('event_type', 'like', 'marketing.%')
        ->where('created_at', '<', now()->subDays($days))
        ->orderBy('id')
        ->limit($limit)
        ->pluck('id');

    if ($ids->isNotEmpty()) {
        DB::table('farast_workflow_events')->whereIn('id', $ids)->delete();
    }

    $this->info('Pruned '.$ids->count().' expired marketing signal(s).');
    return 0;
})->purpose('Delete first-party marketing signals after the retention period');

Schedule::command('store:marketing:prune-signals --days=90 --limit=5000')
    ->dailyAt('03:45')
    ->withoutOverlapping();
