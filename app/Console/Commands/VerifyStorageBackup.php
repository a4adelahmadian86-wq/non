<?php

namespace App\\Console\\Commands;

use App\\Models\\StorageBackupRun;
use App\\Services\\Storage\\StorageBackupService;
use App\\Services\\Storage\\StorageManager;
use Illuminate\\Console\\Command;

class VerifyStorageBackup extends Command
{
    protected $signature = 'storage:verify-backup {runId}';
    protected $description = 'Download and verify the checksum and complete encrypted archive contents of a backup';

    public function handle(StorageManager $storage, StorageBackupService $backup): int
    {
        $run = StorageBackupRun::with('volume')->find($this->argument('runId'));
        if (! $run || $run->status !== 'completed' || ! $run->volume || ! $run->object_key) {
            $this->error('نسخه پشتیبان تکمیل‌شده و قابل دسترسی پیدا نشد.');
            return self::FAILURE;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'farast-backup-check');
        if (! is_string($temporary)) {
            $this->error('ساخت فایل موقت برای بررسی بکاپ ناموفق بود.');
            return self::FAILURE;
        }

        try {
            $storage->downloadTo($storage->volumeDisk($run->volume), $run->object_key, $temporary);
            $checksum = hash_file('sha256', $temporary);
            if (! is_string($checksum) || ! hash_equals((string) $run->checksum, $checksum)) {
                throw new \\RuntimeException('Checksum نسخه پشتیبان با مقدار ثبت‌شده مطابقت ندارد.');
            }

            // Use the same verifier as the upload path so manual verification and
            // post-upload verification cannot silently drift apart.
            $backup->verifyArchive($temporary);

            $this->info('نسخه پشتیبان سالم است: checksum، manifest و تمام جریان دیتابیس رمزگشایی شدند.');
            return self::SUCCESS;
        } catch (\\Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);
            return self::FAILURE;
        } finally {
            @unlink($temporary);
        }
    }
}
