<?php

namespace App\Jobs;

use App\Models\StorageBackupRun;
use App\Services\Storage\StorageBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunStorageBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 14400;

    public function __construct(public readonly int $requestRunId)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(StorageBackupService $backup): void
    {
        $request = StorageBackupRun::findOrFail($this->requestRunId);
        if ($request->status !== 'queued') {
            return;
        }
        $request->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            $results = $backup->runWeekly((string) $request->backup_type);
            $completed = collect($results)->where('status', 'completed')->count();
            $failed = collect($results)->where('status', 'failed')->count();
            $request->update([
                'status' => $completed > 0 && $failed === 0 ? 'completed' : 'failed',
                'completed_at' => now(),
                'metadata' => ['request_only' => true, 'destinations' => $results],
                'error_message' => $failed > 0
                    ? "تکمیل بکاپ در {$failed} مقصد ناموفق بود."
                    : ($completed === 0 ? 'هیچ مقصدی بکاپ را با موفقیت تکمیل نکرد.' : null),
            ]);
        } catch (\Throwable $exception) {
            $request->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        StorageBackupRun::whereKey($this->requestRunId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}