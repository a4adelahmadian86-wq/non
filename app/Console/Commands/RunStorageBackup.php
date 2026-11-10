<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageBackupService;
use Illuminate\Console\Command;

class RunStorageBackup extends Command
{
    protected $signature = 'storage:backup-weekly';
    protected $description = 'Create and deliver an encrypted weekly application backup';

    public function handle(StorageBackupService $backup): int
    {
        try {
            $results = $backup->runWeekly();
            $completed = collect($results)->where('status', 'completed')->count();
            $failed = collect($results)->where('status', 'failed')->count();
            $this->info("Backup destinations completed: {$completed}; failed: {$failed}");
            return $completed > 0 && $failed === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}