<?php

namespace App\Console\Commands;

use App\Models\StorageBackupRun;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;
use ZipArchive;

class VerifyStorageBackup extends Command
{
    protected $signature = 'storage:verify-backup {runId}';
    protected $description = 'Download and verify the checksum and encrypted ZIP structure of a backup';

    public function handle(StorageManager $storage): int
    {
        $run = StorageBackupRun::with('volume')->find($this->argument('runId'));
        if (! $run || $run->status !== 'completed' || ! $run->volume || ! $run->object_key) {
            $this->error('نسخه پشتیبان تکمیل‌شده و قابل دسترسی پیدا نشد.');
            return self::FAILURE;
        }

        $password = (string) config('storage_management.backup.encryption_key', '');
        if ($password === '') {
            $this->error('کلید رمزنگاری بکاپ تنظیم نشده است.');
            return self::FAILURE;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'farast-backup-check');
        try {
            $storage->downloadTo($storage->volumeDisk($run->volume), $run->object_key, $temporary);
            $checksum = hash_file('sha256', $temporary);
            if (! is_string($checksum) || ! hash_equals((string) $run->checksum, $checksum)) {
                throw new \RuntimeException('Checksum نسخه پشتیبان با مقدار ثبت‌شده مطابقت ندارد.');
            }

            $zip = new ZipArchive();
            if ($zip->open($temporary) !== true) throw new \RuntimeException('ساختار ZIP نسخه پشتیبان قابل باز شدن نیست.');
            $zip->setPassword($password);
            $manifest = $zip->getFromName('manifest.json');
            if (! is_string($manifest) || json_decode($manifest, true) === null) {
                $zip->close();
                throw new \RuntimeException('Manifest رمزنگاری‌شده نسخه پشتیبان قابل خواندن نیست.');
            }
            $database = $zip->getStream('database/database.sql');
            if (! is_resource($database)) {
                $zip->close();
                throw new \RuntimeException('فایل دیتابیس در نسخه پشتیبان موجود یا قابل رمزگشایی نیست.');
            }
            $sample = fread($database, 64);
            fclose($database);
            $zip->close();
            if (! is_string($sample) || $sample === '') throw new \RuntimeException('خواندن نمونه دیتابیس ناموفق بود.');

            $this->info('نسخه پشتیبان سالم است: checksum، manifest و جریان دیتابیس تأیید شدند.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);
            return self::FAILURE;
        } finally {
            @unlink($temporary);
        }
    }
}