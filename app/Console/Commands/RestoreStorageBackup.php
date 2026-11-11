<?php

namespace App\Console\Commands;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use App\Models\StorageVolumeReservation;
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
    protected $signature = 'storage:restore-backup {runId} {--confirm}';
    protected $description = 'Restore an encrypted application backup into local staging and keep the site in maintenance mode';

    public function handle(StorageManager $storage, StorageTransferService $transfers): int
    {
        if (! $this->option('confirm')) {
            $this->error('این عملیات مخرب است؛ برای تأیید صریح، گزینه --confirm را وارد کنید.');
            return self::FAILURE;
        }
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
            $storage->downloadTo($storage->volumeDisk($run->volume), $run->object_key, $archive);
            $checksum = hash_file('sha256', $archive);
            if (! is_string($checksum) || ! hash_equals((string) $run->checksum, $checksum)) {
                throw new RuntimeException('Checksum نسخه پشتیبان صحیح نیست.');
            }

            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) throw new RuntimeException('فایل ZIP نسخه پشتیبان باز نشد.');
            $zip->setPassword($password);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $name) || str_contains($name, "\0")) {
                    $zip->close();
                    throw new RuntimeException('مسیر ناامن در آرشیو بازیابی شناسایی شد.');
                }
            }
            $manifestJson = $zip->getFromName('manifest.json');
            if (! is_string($manifestJson)) {
                $zip->close();
                throw new RuntimeException('Manifest رمزنگاری‌شده در بکاپ قابل خواندن نیست.');
            }
            $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
            $databaseDriver = (string) ($manifest['database_driver'] ?? '');
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

            // The database snapshot was taken before this backup object was uploaded.
            // Restore the run's final state and account for this archive exactly once.
            DB::transaction(function () use ($run): void {
                $restoredRun = StorageBackupRun::query()->lockForUpdate()->find($run->id);
                if (! $restoredRun) {
                    throw new RuntimeException('سابقه نسخه پشتیبان در دیتابیس بازیابی‌شده موجود نیست.');
                }

                if ($restoredRun->status === 'running') {
                    $volume = StorageVolume::query()->lockForUpdate()->find($run->storage_volume_id);
                    if (! $volume) {
                        throw new RuntimeException('Volume نسخه پشتیبان در دیتابیس بازیابی‌شده موجود نیست.');
                    }

                    $volume->increment('used_bytes', (int) $run->size_bytes);
                    $volume->increment('object_count');
                    $restoredRun->update([
                        'status' => 'completed',
                        'object_key' => $run->object_key,
                        'checksum' => $run->checksum,
                        'size_bytes' => (int) $run->size_bytes,
                        'completed_at' => $run->completed_at ?? now(),
                    ]);
                }
            });

            $reservationCount = StorageVolumeReservation::query()->count();
            StorageVolumeReservation::query()->update([
                'status' => 'active',
                'expires_at' => now(),
            ]);
            $storage->cleanupExpiredVolumeReservations(max(100, $reservationCount));
            if (StorageVolumeReservation::query()->exists()) {
                throw new RuntimeException('بازیابی کامل نشد؛ برخی رزروهای قدیمی فضای ذخیره‌سازی هنوز پاک‌سازی نشده‌اند.');
            }

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
            if ($database === '' || $database === ':memory:') {
                throw new RuntimeException('مسیر پایگاه داده SQLite برای بازیابی معتبر نیست.');
            }

            File::ensureDirectoryExists(dirname($database));
            $temporary = $database.'.restore-'.bin2hex(random_bytes(8));
            $previous = $database.'.pre-restore-'.bin2hex(random_bytes(8));
            if (! copy($dumpPath, $temporary)) {
                throw new RuntimeException('ساخت فایل موقت پایگاه داده SQLite ناموفق بود.');
            }

            // Remove stale WAL sidecars before replacing the database snapshot.
            @unlink($database.'-wal');
            @unlink($database.'-shm');

            $hadPrevious = is_file($database);
            if ($hadPrevious && ! @rename($database, $previous)) {
                @unlink($temporary);
                throw new RuntimeException('جابجایی امن پایگاه داده فعلی SQLite ناموفق بود.');
            }

            if (! @rename($temporary, $database)) {
                if ($hadPrevious) {
                    @rename($previous, $database);
                }
                @unlink($temporary);
                throw new RuntimeException('جایگزینی اتمیک پایگاه داده SQLite ناموفق بود.');
            }

            if ($hadPrevious) {
                @unlink($previous);
            }
            @unlink($database.'-wal');
            @unlink($database.'-shm');

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
        if (! is_dir($source)) return;

        $parent = dirname($destination);
        File::ensureDirectoryExists($parent);
        $temporary = $parent.DIRECTORY_SEPARATOR.basename($destination).'.restore-'.bin2hex(random_bytes(8));
        $previous = $parent.DIRECTORY_SEPARATOR.basename($destination).'.pre-restore-'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700, true) && ! is_dir($temporary)) {
            throw new RuntimeException('ساخت پوشه موقت بازیابی فایل‌ها ناموفق بود.');
        }

        $previousMoved = false;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    throw new RuntimeException('پیوند نمادین در محتوای استخراج‌شده بکاپ مجاز نیست.');
                }
                if (! $item->isFile()) continue;

                $relative = ltrim(substr($item->getPathname(), strlen($source)), DIRECTORY_SEPARATOR);
                $target = $temporary.DIRECTORY_SEPARATOR.$relative;
                File::ensureDirectoryExists(dirname($target));
                if (! copy($item->getPathname(), $target)) {
                    throw new RuntimeException('کپی فایل محلی بازیابی ناموفق بود: '.$relative);
                }
            }

            $destinationExists = is_dir($destination) || is_file($destination) || is_link($destination);
            if ($destinationExists) {
                if (! @rename($destination, $previous)) {
                    throw new RuntimeException('جابجایی پوشه فعلی برای بازیابی امن ناموفق بود.');
                }
                $previousMoved = true;
            }

            if (! @rename($temporary, $destination)) {
                if ($previousMoved) {
                    @rename($previous, $destination);
                    $previousMoved = false;
                }
                throw new RuntimeException('جایگزینی اتمیک پوشه بازیابی ناموفق بود.');
            }

            if ($previousMoved) {
                if (is_dir($previous) && ! is_link($previous)) {
                    $this->removeDirectory($previous);
                } else {
                    @unlink($previous);
                }
            }
        } catch (\Throwable $exception) {
            if (is_dir($temporary)) {
                $this->removeDirectory($temporary);
            }
            if ($previousMoved && ! file_exists($destination) && ! is_link($destination)) {
                @rename($previous, $destination);
            }
            throw $exception;
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