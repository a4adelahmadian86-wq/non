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
            // Keep archive creation and remote uploads off the scheduler process.
            // The existing job pins execution to the dedicated "backups" queue.
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
