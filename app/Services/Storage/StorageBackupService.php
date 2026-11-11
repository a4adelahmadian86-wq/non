<?php

namespace App\Services\Storage;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Symfony\Component\Process\Process;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class StorageBackupService
{
    public function __construct(private readonly StorageManager $storage) {}

    public function runWeekly(): array
    {
        $backupKey = (string) config('storage_management.backup.encryption_key', '');
        if ($backupKey === '') throw new RuntimeException('برای بکاپ رمزنگاری‌شده، FARAST_BACKUP_ENCRYPTION_KEY باید تنظیم شود.');
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('افزونه ZipArchive برای ساخت بکاپ لازم است.');

        $work = storage_path('app/backup-work/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) throw new RuntimeException('ساخت فضای موقت بکاپ ناموفق بود.');
        $archivePath = $work.'/farast-weekly.zip';
        $results = [];
        $snapshotLock = StorageBackupRun::create([
            'backup_type' => 'weekly',
            'source_name' => 'snapshot_lock',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['lock_only' => true],
        ]);

        try {
            $this->createArchive($archivePath, $work, $backupKey);
            $snapshotLock->update(['status' => 'completed', 'completed_at' => now()]);
            $archiveSize = (int) filesize($archivePath);
            $targets = StorageVolume::query()->where('drive_key', 'foreign_backup')->where('is_active', true)->where('status', 'healthy')
                ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [$archiveSize]))
                ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', $archiveSize))
                ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('object_count < file_limit'))
                ->orderBy('priority')->get();
            if ((bool) config('storage_management.backup.enable_iran_replica', false)) {
                $targets = $targets->concat(StorageVolume::query()->where('drive_key', 'iran_backup')->where('is_active', true)->where('status', 'healthy')
                    ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [$archiveSize]))
                    ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', $archiveSize))
                    ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('object_count < file_limit'))
                    ->orderBy('priority')->get());
            }
            if ($targets->isEmpty()) {
                StorageBackupRun::create([
                    'backup_type' => 'weekly', 'source_name' => 'application',
                    'status' => 'failed', 'error_message' => 'هیچ مقصد سالمی برای بکاپ پیکربندی نشده است.',
                    'completed_at' => now(),
                ]);
                throw new RuntimeException('هیچ هاست بکاپ سالمی پیکربندی نشده است.');
            }

            $checksum = hash_file('sha256', $archivePath);
            foreach ($targets as $target) {
                $run = StorageBackupRun::create([
                    'storage_volume_id' => $target->id,
                    'backup_type' => 'weekly',
                    'source_name' => 'application',
                    'status' => 'running',
                    'started_at' => now(),
                    'size_bytes' => (int) filesize($archivePath),
                    'checksum' => $checksum,
                ]);
                $objectKey = 'weekly/'.now()->format('Y/m/d').'/farast-'.now()->format('Ymd-His').'-'.$run->id.'.zip';
                $reservationKey = null;
                try {
                    $reservationKey = $this->storage->uploadBackup($target, $objectKey, $archivePath, (int) $run->id);
                    $targetDisk = $this->storage->volumeDisk($target);
                    if (! $this->storage->exists($targetDisk, $objectKey)) {
                        throw new RuntimeException('تأیید وجود فایل بکاپ در مقصد ناموفق بود.');
                    }
                    $verificationPath = $work.'/verify-'.$run->id;
                    $this->storage->downloadTo($targetDisk, $objectKey, $verificationPath);
                    $remoteChecksum = hash_file('sha256', $verificationPath);
                    @unlink($verificationPath);
                    if (! is_string($remoteChecksum) || ! hash_equals($checksum, $remoteChecksum)) {
                        throw new RuntimeException('Checksum نسخه پشتیبان پس از انتقال با فایل اصلی مطابقت ندارد.');
                    }
                    DB::transaction(function () use ($run, $objectKey, $checksum, $reservationKey): void {
                        $run->update([
                            'status' => 'completed',
                            'object_key' => $objectKey,
                            'completed_at' => now(),
                            'size_bytes' => (int) filesize($archivePath),
                            'metadata' => ['sha256' => $checksum, 'encrypted' => true, 'encryption' => 'Zip AES-256'],
                        ]);
                        $this->storage->commitVolumeCapacityReservation((string) $reservationKey);
                    });
                    $results[] = ['volume_id' => $target->id, 'status' => 'completed', 'object_key' => $objectKey];
                } catch (\Throwable $exception) {
                    $cleaned = $exception instanceof StorageCapacityException;
                    if (! $cleaned) {
                        try {
                            $this->storage->delete($this->storage->volumeDisk($target), $objectKey);
                            $cleaned = true;
                        } catch (\Throwable $cleanupException) {
                            report($cleanupException);
                        }
                    }

                    if ($cleaned) {
                        $this->storage->releaseBackupCapacityReservation((int) $run->id);
                    }

                    $run->update(['status' => 'failed', 'object_key' => $objectKey, 'completed_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 1000)]);
                    if (! $exception instanceof StorageCapacityException) {
                        $target->update(['status' => 'error', 'last_checked_at' => now()]);
                    }
                    $results[] = ['volume_id' => $target->id, 'status' => 'failed'];
                }
            }
            return $results;
        } catch (\Throwable $exception) {
            $snapshotLock->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        } finally {
            $this->removeDirectory($work);
        }
    }

    private function createArchive(string $archivePath, string $work, string $password): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ساخت فایل فشرده بکاپ ناموفق بود.');
        }
        $zip->setPassword($password);
        $manifest = [
            'created_at' => now()->toIso8601String(),
            'application' => config('app.name'),
            'database_driver' => config('database.connections.'.config('database.default').'.driver', config('database.default')),
            'storage_policy' => 'farast-storage-core-v1',
        ];
        $this->addDirectory($zip, storage_path('app/private'), 'private');
        $this->addDirectory($zip, storage_path('app/public'), 'public');
        $this->addDirectory($zip, storage_path('app/remote-archive'), 'legacy-remote-archive');
        $this->addDatabaseDump($zip, $work);
        $managedCount = 0;
        StorageManagedFile::query()->whereIn('status', ['pending_transfer', 'transfer_error', 'delete_pending'])->whereNull('storage_volume_id')->orderBy('id')->chunkById(100, function ($files) use ($zip, $work, &$managedCount) {
            foreach ($files as $file) {
                $disk = (string) data_get($file->metadata, 'staging_disk', 'private');
                $temporary = $work.'/staged-'.$file->id;
                $this->storage->downloadTo($disk, $file->file_key, $temporary);
                $entry = 'managed-staging/'.$file->id.'/'.basename($file->file_key);
                $this->addEncryptedFile($zip, $temporary, $entry);
                $managedCount++;
            }
        });
        StorageManagedFile::query()->whereIn('status', ['active', 'delete_pending'])->whereNotNull('storage_volume_id')->with('volume')->orderBy('id')->chunkById(100, function ($files) use ($zip, $work, &$managedCount) {
            foreach ($files as $file) {
                if (! $file->volume) continue;
                $temporary = $work.'/managed-'.$file->id;
                $this->storage->downloadTo('storage_volume:'.$file->storage_volume_id, $file->file_key, $temporary);
                $safeKey = str_replace(['..', '\\'], '_', $file->file_key);
                $entry = 'managed/'.$file->storage_volume_id.'/'.$safeKey;
                $this->addEncryptedFile($zip, $temporary, $entry);
                $managedCount++;
            }
        });
        $manifest['managed_files_included'] = $managedCount;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        if (! $zip->close()) throw new RuntimeException('بستن آرشیو بکاپ ناموفق بود.');
    }

    private function addDatabaseDump(ZipArchive $zip, string $work): void
    {
        $connection = config('database.default');
        $config = config('database.connections.'.$connection, []);
        $driver = $config['driver'] ?? $connection;
        $dump = $work.'/database.sql';

        if ($driver === 'sqlite') {
            $database = $config['database'] ?? '';
            if (! is_file($database) || ! copy($database, $dump)) throw new RuntimeException('فایل پایگاه داده SQLite قابل کپی نیست.');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $command = [
                'mysqldump',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? '3306'),
                '--user='.(string) ($config['username'] ?? ''),
                '--single-transaction', '--routines', '--triggers',
                '--result-file='.$dump,
                (string) ($config['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout(3600);
            $process->run();
            if (! $process->isSuccessful()) throw new RuntimeException('تهیه خروجی MySQL ناموفق بود: '.mb_substr($process->getErrorOutput(), 0, 500));
            if (! is_file($dump) || filesize($dump) === 0) throw new RuntimeException('فایل خروجی MySQL خالی یا ایجادنشده است.');
        } elseif ($driver === 'pgsql') {
            $command = [
                'pg_dump', '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? '5432'),
                '--username='.(string) ($config['username'] ?? ''),
                '--format=plain', '--no-owner', '--no-privileges',
                '--file='.$dump,
                (string) ($config['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['PGPASSWORD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout(3600);
            $process->run();
            if (! $process->isSuccessful()) throw new RuntimeException('تهیه خروجی PostgreSQL ناموفق بود: '.mb_substr($process->getErrorOutput(), 0, 500));
            if (! is_file($dump) || filesize($dump) === 0) throw new RuntimeException('فایل خروجی PostgreSQL خالی یا ایجادنشده است.');
        } else {
            throw new RuntimeException('درایور پایگاه داده برای بکاپ پشتیبانی نشده است.');
        }

        $this->addEncryptedFile($zip, $dump, 'database/database.sql');
    }

    private function addDirectory(ZipArchive $zip, string $directory, string $prefix): void
    {
        if (! is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) continue;
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/');
            $this->addEncryptedFile($zip, $file->getPathname(), $prefix.'/'.$relative);
        }
    }

    private function addEncryptedFile(ZipArchive $zip, string $path, string $entry): void
    {
        if (! $zip->addFile($path, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('افزودن فایل رمزنگاری‌شده به بکاپ ناموفق بود: '.$entry);
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