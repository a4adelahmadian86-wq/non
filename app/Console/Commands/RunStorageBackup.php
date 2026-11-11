<?php

namespace App\Console\Commands;

use App\Jobs\RunStorageBackupJob;
use App\Models\StorageBackupRun;
use Illuminate\Console\Command;

class RunStorageBackup extends Command
{
    protected $signature = 'storage:backup-weekly';
    protected $description = 'Queue an encrypted weekly application backup';

    public function handle(): int
    {
        $request = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'scheduled_request',
            'status' => 'queued',
            'started_at' => now(),
            'metadata' => ['request_only' => true, 'trigger' => 'scheduler'],
        ]);

        try {
            // The job pins itself to the dedicated "backups" connection/queue.
            // The scheduler only records and enqueues work; it never builds a
            // multi-gigabyte archive inside the scheduler process.
            RunStorageBackupJob::dispatch($request->id);
        } catch (\Throwable $exception) {
            $request->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            report($exception);
            $this->error('ثبت درخواست بکاپ در صف ناموفق بود.');
            return self::FAILURE;
        }

        $this->info('درخواست بکاپ هفتگی در صف اختصاصی بکاپ ثبت شد.');
        return self::SUCCESS;
    }
}
