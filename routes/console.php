<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\WorkflowEngine;
use App\Services\StoreCartAbandonmentService;
use App\Models\SiteSetting;


use App\Services\StorageBackupService;

Artisan::command('farast:storage-backup', function (StorageBackupService $backups) {
    $run = $backups->runWeekly();
    if ($run->status !== 'completed') {
        $this->error($run->error_message ?: 'Backup failed.');
        return 1;
    }
    $this->info('Backup #'.$run->id.' completed and verified.');
    return 0;
})->purpose('Create and verify an encrypted off-site backup of database and files');

Artisan::command('farast:storage-backup:restore-files {run_id} {--overwrite}', function (\App\Services\StorageBackupRestoreService $restore) {
    $result = $restore->restoreFiles((int) $this->argument('run_id'), (bool) $this->option('overwrite'));
    $this->info('Managed files restored: '.$result['managed']);
    $this->info('Legacy files restored: '.$result['legacy']);
    $this->info('Files skipped: '.$result['skipped']);
    $this->warn('Database dump staged for manual restore at: '.$result['database_dump']);
})->purpose('Verify and restore files from an encrypted backup; stage database dump for manual restore');

Artisan::command('farast:storage-backup:password {run_id}', function (\App\Services\StorageBackupRestoreService $restore) {
    $this->line($restore->password((int) $this->argument('run_id')));
})->purpose('Reveal the decryption password for a completed backup from the CLI');

Artisan::command('farast:storage-health {--limit=100}', function (\App\Services\StorageHealthService $health) {
    $result = $health->check((int) $this->option('limit'));
    $this->info('Checked '.$result['checked'].' storage host(s); '.$result['healthy'].' healthy, '.$result['failed'].' failed.');
    return $result['failed'] > 0 ? 1 : 0;
})->purpose('Probe connected storage hosts and update their health status');

Schedule::command('farast:storage-health --limit=100')
    ->everyTenMinutes()
    ->withoutOverlapping();

Artisan::command('farast:storage-replicate-pending {--limit=100}', function () {
    $volumes = \App\Models\StorageVolume::query()
        ->where('drive_key', 'iran_primary')
        ->where('is_active', true)
        ->where('status', 'healthy')
        ->get(['id', 'provider']);
    $providers = $volumes->pluck('provider')->filter()->unique()->count();
    if ($providers < 2) {
        $this->info('A second healthy Iranian provider is not configured; replicas remain pending.');
        return 0;
    }

    $ids = \App\Models\StorageManagedFile::query()
        ->where('drive_key', 'iran_primary')
        ->where('classification', 'critical')
        ->where('status', 'active')
        ->where(function ($query) {
            $query->whereNull('replicated_to_volume_id')
                ->orWhereHas('replicaVolume', fn ($replica) => $replica->where('status', '!=', 'healthy'));
        })
        ->orderBy('id')
        ->limit(max(1, min(500, (int) $this->option('limit'))))
        ->pluck('id');

    foreach ($ids as $id) {
        \App\Jobs\ReplicateStorageFile::dispatch((int) $id);
    }
    $this->info('Queued '.$ids->count().' critical storage replica job(s).');
    return 0;
})->purpose('Queue missing same-region replicas for critical stored files');

Schedule::command('farast:storage-replicate-pending --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('farast:storage-backup')
    ->weeklyOn(0, '02:30')
    ->withoutOverlapping();

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
