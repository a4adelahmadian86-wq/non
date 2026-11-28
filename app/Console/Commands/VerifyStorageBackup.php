<?php

namespace App\Console\Commands;

use App\Models\StorageBackupRun;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;

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
                throw new \RuntimeException('Checksum نسخه پشتیبان با مقدار ثبت‌شده مطابقت ندارد.');
            }

            if ((int) data_get($run->metadata, 'format_version', 0) === 3) {
                $work = storage_path('app/backup-verify-work/'.bin2hex(random_bytes(12)));
                if (! mkdir($work, 0700, true) && ! is_dir($work)) {
                    throw new \\RuntimeException('ساخت فضای موقت بررسی بکاپ افزایشی ناموفق بود.');
                }
                try {
                    $backup->materializeIncrementalManifest($run, $temporary, $work, $work.'/extracted');
                } finally {
                    $this->removeDirectory($work);
                }
            } else {
                // Legacy ZIP v1/v2 backups remain verifiable.
                $backup->verifyArchive($temporary);
            }

            $this->info('نسخه پشتیبان سالم است: checksum، Manifest و تمام Blobهای رمزنگاری‌شده بررسی شدند.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);
            return self::FAILURE;
        } finally {
            @unlink($temporary);
        }
    }
    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

}
