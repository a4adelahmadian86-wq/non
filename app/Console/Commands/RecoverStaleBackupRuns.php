<?php

namespace App\Console\Commands;

use App\Models\StorageBackupRun;
use Illuminate\Console\Command;

class RecoverStaleBackupRuns extends Command
{
    protected $signature = 'storage:recover-stale-backup-runs {--running-hours=5} {--queued-hours=24}';
    protected $description = 'Release abandoned backup requests, backup runs and snapshot locks after worker timeouts';

    public function handle(): int
    {
        $runningHours = max(1, min(72, (int) $this->option('running-hours')));
        $queuedHours = max($runningHours, min(168, (int) $this->option('queued-hours')));
        $now = now();
        $runningCutoff = $now->copy()->subHours($runningHours);
        $queuedCutoff = $now->copy()->subHours($queuedHours);

        $staleRunning = StorageBackupRun::query()
            ->whereIn('source_name', ['scheduler_request', 'manual_request', 'snapshot_lock', 'application'])
            ->where('status', 'running')
            ->where(function ($query) use ($runningCutoff): void {
                $query->where('started_at', '<', $runningCutoff)
                    ->orWhere(function ($query) use ($runningCutoff): void {
                        $query->whereNull('started_at')
                            ->where('created_at', '<', $runningCutoff);
                    });
            })
            ->update([
                'status' => 'failed',
                'completed_at' => $now,
                'error_message' => 'اجرای بکاپ یا قفل Snapshot از مهلت Worker عبور کرد و به‌عنوان اجرای متوقف‌شده ثبت شد.',
            ]);

        $staleQueued = StorageBackupRun::query()
            ->whereIn('source_name', ['scheduler_request', 'manual_request'])
            ->where('status', 'queued')
            ->where('created_at', '<', $queuedCutoff)
            ->update([
                'status' => 'failed',
                'completed_at' => $now,
                'error_message' => 'درخواست بکاپ بیش از مهلت مجاز در صف باقی ماند و توسط Worker دریافت نشد.',
            ]);

        $this->info("Recovered {$staleRunning} stale running backup record(s) and {$staleQueued} stale queued request(s).");

        return self::SUCCESS;
    }
}
