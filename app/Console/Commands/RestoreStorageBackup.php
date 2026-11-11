<?php

namespace App\Console\Commands;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Services\Storage\StorageManager;
use App\Services\Storage\StorageTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use RuntimeException;
use ZipArchive;

class RestoreStorageBackup extends Command
{
    protected $signature = 'storage:restore-backup {runId?} {--archive=} {--confirm}';
    protected $description = 'Restore an encrypted application backup into local staging and keep the site in maintenance mode';

    public function handle(StorageManager $storage, StorageTransferService $transfers, StorageBackupService $backup): int
    {
        if (! $this->option('confirm')) {
            $this->error('این عملیات مخرب است؛ برای تأیید صریح، گزینه --confirm را وارد کنید.');
            return self::FAILURE;
        }
        $runId = $this->argument('runId');
        $archiveOption = $this->option('archive');
        if (($runId && $archiveOption) || (! $runId && ! $archiveOption)) {
            $this->error('یک شناسه بکاپ یا مسیر آرشیو محلی را انتخاب کنید؛ هر دو هم‌زمان مجاز نیستند.');
            return self::FAILURE;
        }

        $run = null;
        $sourceArchive = null;
        if ($runId) {
            $run = StorageBackupRun::with('volume')->find($runId);
            if (! $run || $run->status !== 'completed' || ! $run->volume || ! $run->object_key) {
                $this->error('نسخه پشتیبان تکمیل‌شده و قابل دسترسی پیدا نشد.');
                return self::FAILURE;
            }
        } else {
            $archivePath = (string) $archiveOption;
            $sourceArchive = realpath($archivePath);
            if (
                $sourceArchive === false
                || ! is_file($sourceArchive)
                || ! is_readable($sourceArchive)
                || is_link($archivePath)
            ) {
                $this->error('آرشیو محلی برای بازیابی وجود ندارد یا قابل خواندن نیست.');
                return self::FAILURE;
            }
        }
        $password = (string) config('storage_management.backup.encryption_key', '');
        if ($password === '') {
            $this->error('کلید رمزنگاری بکاپ تنظیم نشده است.');
            return self::FAILURE;
        }
        if (! class_exists(ZipArchive::class)) {
            $this->error('افزونه ZipArchive نصب نیست.');
            return self::FAILURE;
        }

        $work = storage_path('app/restore-work/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            $this->error('ساخت فضای موقت بازیابی ناموفق بود.');
            return self::FAILURE;
        }
        $archive = $work.'/backup.zip';
        $extracted = $work.'/extracted';
        $maintenanceEnabled = false;

        try {
            if ($run) {
                $storage->downloadTo($storage->volumeDisk($run->volume), $run->object_key, $archive);
            } elseif (! is_string($sourceArchive) || ! copy($sourceArchive, $archive)) {
                throw new RuntimeException('کپی آرشیو محلی به فضای موقت بازیابی ناموفق بود.');
            }

            $checksum = hash_file('sha256', $archive);
            if (! is_string($checksum) || ($run && ! hash_equals((string) $run->checksum, $checksum))) {
                throw new RuntimeException('Checksum نسخه پشتیبان صحیح نیست.');
            }
            // For a local bootstrap restore, the encrypted manifest and per-entry
            // AES authentication are the trust anchor; no database run row is needed.
            $backup->verifyArchive($archive);

            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) throw new RuntimeException('فایل ZIP نسخه پشتیبان باز نشد.');
            if (! $zip->setPassword($password)) {
                $zip->close();
                throw new RuntimeException('تنظیم کلید رمزگشایی آرشیو بازیابی ناموفق بود.');
            }
            $seenEntries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (
                    ! is_string($name)
                    || $name === ''
                    || strlen($name) > 4096
                    || str_starts_with($name, '/')
                    || preg_match('/^[A-Za-z]:/', $name)
                    || str_contains($name, '\\')
                    || preg_match('#(^|/)\\.\\.?(/|$)#', $name)
                    || str_contains($name, "\0")
                    || isset($seenEntries[$name])
                ) {
                    $zip->close();
                    throw new RuntimeException('مسیر تکراری یا ناامن در آرشیو بازیابی شناسایی شد.');
                }
                $seenEntries[$name] = true;

                $operatingSystem = 0;
                $externalAttributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $externalAttributes)) {
                    $unixFileType = ($externalAttributes >> 16) & 0170000;
                    if ($operatingSystem === ZipArchive::OPSYS_UNIX && $unixFileType === 0120000) {
                        $zip->close();
                        throw new RuntimeException('پیوند نمادین در آرشیو بازیابی مجاز نیست.');
                    }
                }
            }
            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson)) {
                $zip->close();
                throw new RuntimeException('Manifest رمزنگاری‌شده در بکاپ قابل خواندن نیست.');
            }
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            $databaseDriver = (string) ($manifest['database_driver'] ?? '');
            $currentDriver = (string) config(
                'database.connections.'.config('database.default').'.driver',
                config('database.default')
            );
            if (! in_array($databaseDriver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true) || $databaseDriver !== $currentDriver) {
                $zip->close();
                throw new RuntimeException('درایور پایگاه داده بکاپ با درایور پشتیبانی‌شده و محیط مقصد یکسان نیست.');
            }
            if (! $zip->locateName('database/database.sql')) {
                $zip->close();
                throw new RuntimeException('فایل پایگاه داده در آرشیو موجود نیست.');
            }
            File::ensureDirectoryExists($extracted);
            if (! $zip->extractTo($extracted)) {
                $zip->close();
                throw new RuntimeException('استخراج فایل‌های رمزنگاری‌شده بکاپ ناموفق بود.');
            }
            $zip->close();

            Artisan::call('down', ['--retry' => 60]);
            $maintenanceEnabled = true;
            $this->restoreDirectory($extracted.'/private', storage_path('app/private'));
            $this->restoreDirectory($extracted.'/public', storage_path('app/public'));
            $this->restoreDirectory($extracted.'/legacy-remote-archive', storage_path('app/remote-archive'));

            DB::purge();
            $this->restoreDatabase($databaseDriver, $extracted.'/database/database.sql');
            DB::purge();
            DB::reconnect();

            // The backup run itself was recorded after its database snapshot. Recreate
            // its audit row and account for the already-present backup object after restore.
            $restoredRun = StorageBackupRun::query()->find($run->id);
            if (! $restoredRun) {
                $restoredRun = new StorageBackupRun();
                $restoredRun->id = $run->id;
                $volume = StorageVolume::query()->find($run->storage_volume_id);
                if (! $volume) {
                    throw new RuntimeException('مقصد نسخه پشتیبان در پایگاه داده بازیابی‌شده موجود نیست.');
                }
                $volume->increment('used_bytes', max(0, (int) $run->size_bytes));
                $volume->increment('object_count');
            }
            $runMetadata = (array) ($run->metadata ?? []);
            $runMetadata['restored_at'] = now()->toIso8601String();
            $restoredRun->forceFill([
                'storage_volume_id' => $run->storage_volume_id,
                'backup_type' => $run->backup_type,
                'source_name' => $run->source_name,
                'status' => 'completed',
                'object_key' => $run->object_key,
                'size_bytes' => $run->size_bytes,
                'checksum' => $run->checksum,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'metadata' => $runMetadata,
            ])->save();

            // The snapshot necessarily captured its own lock row as "running".
            // It is no longer active after a restore and must not defer deletes for hours.
            StorageBackupRun::query()
                ->where('source_name', 'snapshot_lock')
                ->where('status', 'running')
                ->update(['status' => 'completed', 'completed_at' => now(), 'error_message' => null]);

            // Scheduled and manual requests are marked running before their backup job
            // takes the snapshot. Complete both after restore so neither remains stuck.
            StorageBackupRun::query()
                ->whereIn('source_name', ['scheduler_request', 'manual_request'])
                ->whereIn('status', ['queued', 'running'])
                ->get()
                ->each(function (StorageBackupRun $request) use ($run): void {
                    $metadata = (array) ($request->metadata ?? []);
                    $metadata['restored_at'] = now()->toIso8601String();
                    $metadata['restored_from_backup_run_id'] = (int) $run->id;
                    $request->forceFill([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'error_message' => null,
                        'metadata' => $metadata,
                    ])->save();
                });

            $restored = $this->stageRemoteObjects($extracted, $transfers);
            $this->stagePendingObjects($extracted);

            $this->newLine();
            $this->info('بازیابی پایگاه داده و فایل‌ها انجام شد.');
            $this->info("فایل‌های فضای ابری که به staging محلی بازگردانده شدند: {$restored}");
            $this->warn('سایت در حالت تعمیر و نگهداری باقی می‌ماند. پس از بررسی سلامت داده‌ها و مقصدهای ذخیره‌سازی، دستور php artisan up را اجرا کنید.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            if ($maintenanceEnabled) $this->warn('حالت تعمیر و نگهداری فعال باقی مانده است تا از سرویس‌دهی با داده‌های ناقص جلوگیری شود.');
            return self::FAILURE;
        } finally {
            $this->removeDirectory($work);
        }
    }

    private function restoreDatabase(string $backupDriver, string $dumpPath): void
    {
        $connection = config('database.default');
        $config = config('database.connections.'.$connection, []);
        $driver = (string) ($config['driver'] ?? $connection);
        if ($backupDriver !== $driver) throw new RuntimeException('درایور پایگاه داده بکاپ با درایور فعلی برنامه یکسان نیست.');

        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? '');
            if ($database === '' || $database === ':memory:' || ! copy($dumpPath, $database)) {
                throw new RuntimeException('بازگردانی فایل پایگاه داده SQLite ناموفق بود.');
            }
            return;
        }

        $input = fopen($dumpPath, 'rb');
        if (! is_resource($input)) throw new RuntimeException('فایل SQL برای بازیابی قابل خواندن نیست.');
        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $process = new Process([
                    'mysql',
                    '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($config['port'] ?? '3306'),
                    '--user='.(string) ($config['username'] ?? ''),
                    (string) ($config['database'] ?? ''),
                ], base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            } elseif ($driver === 'pgsql') {
                $process = new Process([
                    'psql', '--set=ON_ERROR_STOP=1',
                    '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($config['port'] ?? '5432'),
                    '--username='.(string) ($config['username'] ?? ''),
                    '--dbname='.(string) ($config['database'] ?? ''),
                ], base_path(), ['PGPASSWORD' => (string) ($config['password'] ?? '')]);
            } else {
                throw new RuntimeException('درایور پایگاه داده برای بازیابی پشتیبانی نمی‌شود.');
            }
            $process->setInput($input);
            $process->setTimeout(7200);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('بازیابی پایگاه داده ناموفق بود: '.mb_substr($process->getErrorOutput(), 0, 1000));
            }
        } finally {
            fclose($input);
        }
    }

    private function stageRemoteObjects(string $extracted, StorageTransferService $transfers): int
    {
        $count = 0;
        StorageManagedFile::query()->where('status', 'active')->whereNotNull('storage_volume_id')->orderBy('id')->chunkById(100, function ($files) use ($extracted, $transfers, &$count) {
            foreach ($files as $file) {
                $safeKey = str_replace(['..', '\\'], '_', $file->file_key);
                $source = $extracted.'/managed/'.$file->storage_volume_id.'/'.$safeKey;
                if (! is_file($source)) throw new RuntimeException('فایل مدیریت‌شده در بکاپ موجود نیست: '.$file->id);
                $stagingKey = 'restore-recovery/'.$file->id.'/'.basename($file->file_key);
                $target = Storage::disk('private')->path($stagingKey);
                File::ensureDirectoryExists(dirname($target));
                if (! copy($source, $target)) throw new RuntimeException('انتقال فایل بازیابی‌شده به staging ناموفق بود: '.$file->id);
                $transfers->rebindToStaging($file, $stagingKey);
                $count++;
            }
        });
        return $count;
    }

    private function stagePendingObjects(string $extracted): void
    {
        StorageManagedFile::query()->whereNull('storage_volume_id')->whereIn('status', ['pending_transfer', 'transfer_error'])->orderBy('id')->chunkById(100, function ($files) use ($extracted) {
            foreach ($files as $file) {
                $target = Storage::disk('private')->path($file->file_key);
                if (is_file($target)) continue;
                $source = $extracted.'/managed-staging/'.$file->id.'/'.basename($file->file_key);
                if (! is_file($source)) throw new RuntimeException('فایل staging در بکاپ پیدا نشد: '.$file->id);
                File::ensureDirectoryExists(dirname($target));
                if (! copy($source, $target)) throw new RuntimeException('بازگردانی فایل staging ناموفق بود: '.$file->id);
            }
        });
    }

    private function restoreDirectory(string $source, string $destination): void
    {
        // A restore is replacement, not a merge: remove files created after the snapshot.
        // Refuse symlink roots so a malformed host layout cannot redirect destructive cleanup.
        if (is_link($destination)) {
            throw new RuntimeException('مسیر مقصد بازیابی نباید پیوند نمادین باشد.');
        }
        if (is_dir($destination)) {
            File::deleteDirectory($destination);
        }
        if (! is_dir($source)) return;

        File::ensureDirectoryExists($destination);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (! $item->isFile()) continue;
            $relative = ltrim(substr($item->getPathname(), strlen($source)), DIRECTORY_SEPARATOR);
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            File::ensureDirectoryExists(dirname($target));
            if (! copy($item->getPathname(), $target)) throw new RuntimeException('کپی فایل محلی بازیابی ناموفق بود: '.$relative);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}