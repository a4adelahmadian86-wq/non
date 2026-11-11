<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Services\WorkflowEngine;
use App\Services\StoreCartAbandonmentService;
use App\Models\SiteSetting;

// Probe active storage providers and refresh provider-reported usage/counts.
Schedule::command('storage:check-volumes --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Retry staged uploads after remote storage becomes reachable; local staging is never pruned.
Schedule::command('storage:transfer-pending --limit=100')
    ->everyMinute()
    ->withoutOverlapping(10);

// Complete deletes that were intentionally deferred during a backup snapshot.
Schedule::command('storage:cleanup-deferred-deletes --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Encrypted weekly application backup to configured foreign backup volumes.
Schedule::command('storage:backup-weekly')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping(600);

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

// Run long backup jobs on the first-party database-backed backups connection.
// A separate worker keeps the four-hour backup timeout independent of short document jobs.
Schedule::command('queue:work backups --queue=backups --stop-when-empty --max-time=900 --timeout=14400 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(300)
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
    $queued = $engine->dispatchPendingEvents((int) $this->option('limit'));
    $this->info("Queued {$queued} pending or recoverable workflow event(s).");
})->purpose('Retry failed FARAST workflow event steps');

Schedule::command('farast:workflows:retry --limit=50')
    ->everyMinute()
    ->withoutOverlapping(10);

// Dedicated database-backed automation worker; sync mode is intentionally excluded.
Schedule::command('queue:work --queue=automation --stop-when-empty --max-time=900 --timeout=300 --tries=5 --backoff=60')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->when(fn () => (bool) config('queue.scheduled_worker', true) && config('queue.default') !== 'sync');

Artisan::command('farast:workflows:pause {eventId}', function (WorkflowEngine $engine) {
    $paused = $engine->pauseEvent((int) $this->argument('eventId'));
    $this->info($paused ? 'Workflow event paused at a safe boundary.' : 'Event not found or already completed.');
    return $paused ? 0 : 1;
})->purpose('Pause a workflow event without deleting its history');

Artisan::command('farast:workflows:resume {eventId}', function (WorkflowEngine $engine) {
    $resumed = $engine->resumeEvent((int) $this->argument('eventId'));
    $message = config('queue.default') === 'sync'
        ? 'Event resumed and stored as pending; configure QUEUE_CONNECTION=database and start the automation worker.'
        : 'Workflow event queued for recovery.';
    $this->info($resumed ? $message : 'Event is not paused or does not exist.');
    return $resumed ? 0 : 1;
})->purpose('Resume a paused workflow event');

Artisan::command('farast:workflows:retry-event {eventId}', function (WorkflowEngine $engine) {
    $retried = $engine->retryFailedEvent((int) $this->argument('eventId'));
    $message = config('queue.default') === 'sync'
        ? 'Event reset to pending; configure QUEUE_CONNECTION=database and start the automation worker.'
        : 'Terminally failed event queued for a new recovery cycle.';
    $this->info($retried ? $message : 'Event is not in terminal failed state.');
    return $retried ? 0 : 1;
})->purpose('Manually retry a terminally failed workflow event');

Artisan::command('farast:workflows:health', function () {
    if (! Schema::hasTable('farast_workflow_events')) {
        $this->warn('Workflow event ledger is not installed.');
        return 1;
    }
    $counts = DB::table('farast_workflow_events')
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')->pluck('total', 'status');
    $oldest = DB::table('farast_workflow_events')->where('status', 'pending')->orderBy('created_at')->first();
    $delay = $oldest ? max(0, (int) Illuminate\Support\Carbon::parse($oldest->created_at)->diffInSeconds(now())) : 0;
    $staleProcessing = DB::table('farast_workflow_events')->where('status', 'processing')
        ->where('updated_at', '<=', now()->subMinutes(30))->count();
    $queueJobs = Schema::hasTable('jobs') ? DB::table('jobs')->where('queue', 'automation')->count() : 0;
    $oldestQueuedJob = Schema::hasTable('jobs')
        ? DB::table('jobs')->where('queue', 'automation')->orderBy('created_at')->first()
        : null;
    $queueDelay = $oldestQueuedJob
        ? max(0, now()->getTimestamp() - (int) $oldestQueuedJob->created_at)
        : 0;
    $failedQueueJobs = Schema::hasTable('failed_jobs')
        ? DB::table('failed_jobs')->where('queue', 'automation')->count()
        : 0;
    $failed = (int) ($counts['failed'] ?? 0);
    $pending = (int) ($counts['pending'] ?? 0);
    $queueConnection = (string) config('queue.default');
    $this->line('queue_connection='.$queueConnection.' pending='.$pending.' processing='.(int) ($counts['processing'] ?? 0).' stale_processing='.$staleProcessing.' failed='.$failed.' paused='.(int) ($counts['paused'] ?? 0).' queue_jobs='.$queueJobs.' failed_queue_jobs='.$failedQueueJobs.' oldest_pending_seconds='.$delay.' oldest_queue_seconds='.$queueDelay);
    $unhealthy = ($queueConnection === 'sync' && $pending > 0)
        || $failed > 0
        || $staleProcessing > 0
        || $failedQueueJobs > 0
        || $delay >= 300
        || $queueDelay >= 300;

    if ($unhealthy) {
        Log::warning('FARAST workflow queue health threshold exceeded', [
            'queue_connection' => $queueConnection,
            'pending' => $pending,
            'failed' => $failed,
            'stale_processing' => $staleProcessing,
            'queue_jobs' => $queueJobs,
            'failed_queue_jobs' => $failedQueueJobs,
            'oldest_pending_seconds' => $delay,
            'oldest_queue_seconds' => $queueDelay,
        ]);
        $this->error('Workflow queue is unhealthy; inspect the metrics above and application logs.');
        return 1;
    }

    $this->info('Workflow queue health is within configured thresholds.');
    return 0;
})->purpose('Report workflow queue backlog, latency and terminal failures');

Schedule::command('farast:workflows:health')
    ->everyFiveMinutes()
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

Artisan::command('store:marketing:prune-signals {--limit=5000}', function () {
    if (! Schema::hasTable('farast_workflow_events')) {
        $this->info('Marketing signal storage is not installed; nothing to prune.');
        return 0;
    }

    $days = max(7, min(180, (int) SiteSetting::read('marketing_signal_retention_days', 90)));
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

Schedule::command('store:marketing:prune-signals --limit=5000')
    ->dailyAt('03:45')
    ->withoutOverlapping();
