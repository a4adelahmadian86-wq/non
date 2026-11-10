<?php

namespace App\Jobs;

use App\Services\StorageBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunStorageBackup implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;
    public int $uniqueFor = 43200;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'farast-weekly-storage-backup';
    }

    public function handle(StorageBackupService $backups): void
    {
        $run = $backups->runWeekly();
        if ($run->status !== 'completed') {
            throw new \RuntimeException($run->error_message ?: 'Scheduled storage backup failed.');
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
