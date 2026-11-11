<?php

namespace App\Console\Commands;

use App\Jobs\RunStorageBackupJob;
use App\Models\StorageBackupRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunStorageBackup extends Command
{
    protected $signature = 'storage:backup-weekly';
    protected $description = 'Queue an encrypted weekly application backup on the dedicated backups connection';

    public function handle(): int
    {
        $lock = Cache::lock('farast:backup:dispatch', 30);
        if (! $lock->get()) {
            $this->info('A backup dispatch is already being prepared.');
            return self::SUCCESS;
        }

        try {
            // The dedicated worker has a four-hour hard timeout and a 4h10m
            // retry_after. A running request older than five hours is abandoned,
            // not an active backup; recover it so one killed worker cannot block
            // every future scheduled backup indefinitely.
            StorageBackupRun::query()
                ->where('source_name', 'scheduler_request')
                ->where('status', 'running')
                ->whereNotNull('started_at')
                ->where('started_at', '<', now()->subHours(5))
                ->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => 'درخواست بکاپ پس از پایان مهلت اجرای Worker بدون نتیجه باقی مانده بود.',
                ]);

            // A queued request older than one day indicates that no backup worker
            // has consumed it. Do not let a permanently unavailable queue suppress
            // the next scheduled backup forever.
            StorageBackupRun::query()
                ->where('source_name', 'scheduler_request')
                ->where('status', 'queued')
                ->where('created_at', '<', now()->subDay())
                ->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => 'درخواست بکاپ بیش از ۲۴ ساعت در صف باقی مانده و توسط Worker دریافت نشده بود.',
                ]);

            $existing = StorageBackupRun::query()
                ->where('source_name', 'scheduler_request')
                ->whereIn('status', ['queued', 'running'])
                ->latest('id')
                ->first();

            if ($existing) {
                $this->info('An active backup request already exists: #'.$existing->id);
                return self::SUCCESS;
            }

            $request = StorageBackupRun::create([
                'backup_type' => 'weekly',
                'source_name' => 'scheduler_request',
                'status' => 'queued',
                'metadata' => ['request_only' => true],
            ]);

            try {
                RunStorageBackupJob::dispatch($request->id);
            } catch (Throwable $exception) {
                $request->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
                throw $exception;
            }

            $this->info('Encrypted backup queued on the backups worker: #'.$request->id);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
