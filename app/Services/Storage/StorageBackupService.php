<?php

namespace App\Services\Storage;

use App\Models\StorageBackupRun;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use RuntimeException;
use ZipArchive;

class StorageBackupService
{
    public function __construct(private readonly StorageManager $storage) {}

    public function verifyArchive(string $archivePath): void
    {
        $backupKey = (string) config('storage_management.backup.encryption_key', '');
        if ($backupKey === '') {
            throw new RuntimeException('کلید رمزنگاری بکاپ برای بررسی آرشیو تنظیم نشده است.');
        }

        $this->assertArchiveReadable($archivePath, $backupKey);
    }

    public function runWeekly(): array
    {
        // Prevent concurrent snapshots from racing volume capacity accounting and uploads.
        // The worker hard timeout is four hours; the six-hour lease leaves recovery headroom.
        $lock = Cache::lock('farast:backup:execution', 21600);
        if (! $lock->get()) {
            throw new \RuntimeException('A backup operation is already running.');
        }

        try {
            return $this->runWeeklyLocked();
        } finally {
            $lock->release();
        }
    }

    private function runWeeklyLocked(): array
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
                try {
                    $this->storage->uploadBackup($target, $objectKey, $archivePath);
                    $targetDisk = $this->storage->volumeDisk($target);
                    if (! $this->storage->exists($targetDisk, $objectKey)) {
                        throw new RuntimeException('تأیید وجود فایل بکاپ در مقصد ناموفق بود.');
                    }
                    $verificationPath = $work.'/verify-'.$run->id;
                    $this->storage->downloadTo($targetDisk, $objectKey, $verificationPath);
                    $remoteChecksum = hash_file('sha256', $verificationPath);
                    if (! is_string($remoteChecksum) || ! hash_equals($checksum, $remoteChecksum)) {
                        throw new RuntimeException('Checksum نسخه پشتیبان پس از انتقال با فایل اصلی مطابقت ندارد.');
                    }
                    $this->assertArchiveReadable($verificationPath, $backupKey);
                    @unlink($verificationPath);
                    $run->update([
                        'status' => 'completed', 'object_key' => $objectKey,
                        'completed_at' => now(),
                        'metadata' => ['sha256' => $checksum, 'encrypted' => true, 'encryption' => 'Zip AES-256'],
                    ]);
                    $target->increment('used_bytes', (int) filesize($archivePath));
                    $target->increment('object_count');
                    $results[] = ['volume_id' => $target->id, 'status' => 'completed', 'object_key' => $objectKey];
                } catch (\Throwable $exception) {
                    try {
                        $this->storage->delete($this->storage->volumeDisk($target), $objectKey);
                    } catch (\Throwable $cleanupException) {
                        report($cleanupException);
                    }
                    $run->update(['status' => 'failed', 'object_key' => $objectKey, 'completed_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 1000)]);
                    $target->update(['status' => 'error', 'last_checked_at' => now()]);
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

    private function assertArchiveReadable(string $archivePath, string $password): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('آرشیو پشتیبان پس از انتقال قابل باز شدن نیست.');
        }

        try {
            if (! $zip->setPassword($password)) {
                throw new RuntimeException('تنظیم کلید رمزگشایی آرشیو پشتیبان ناموفق بود.');
            }

            $manifestJson = $zip->getFromName('manifest.json');
            try {
                $manifest = is_string($manifestJson)
                    ? json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR)
                    : null;
            } catch (\JsonException $exception) {
                throw new RuntimeException('Manifest رمزنگاری‌شده بکاپ JSON معتبر نیست.', 0, $exception);
            }

            $entries = is_array($manifest) ? ($manifest['entries'] ?? null) : null;
            if (
                ! is_array($manifest)
                || ($manifest['format_version'] ?? null) !== 2
                || empty($manifest['database_driver'])
                || ! is_array($entries)
                || count($entries) < 1
                || (int) ($manifest['entry_count'] ?? -1) !== count($entries)
            ) {
                throw new RuntimeException('Manifest رمزنگاری‌شده بکاپ ناقص یا ناسازگار است.');
            }

            ksort($entries, SORT_STRING);
            $expectedHmac = hash_hmac('sha256', $this->canonicalizeEntries($entries), $password);
            if (! hash_equals($expectedHmac, (string) ($manifest['inventory_hmac'] ?? ''))) {
                throw new RuntimeException('امضای موجودی فایل‌های بکاپ معتبر نیست.');
            }

            foreach ($entries as $entryName => $metadata) {
                if (
                    ! is_string($entryName)
                    || $entryName === ''
                    || ! is_array($metadata)
                    || ! is_int($metadata['size_bytes'] ?? null)
                    || $metadata['size_bytes'] < 0
                    || ! is_string($metadata['sha256'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/i', $metadata['sha256'])
                ) {
                    throw new RuntimeException('ساختار موجودی فایل‌های بکاپ نامعتبر است.');
                }
            }

            $seenNames = [];
            $seenEntries = [];
            $databaseBytes = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($name === '') {
                    throw new RuntimeException('آرشیو بکاپ شامل ورودی بدون نام است.');
                }
                if (isset($seenNames[$name])) {
                    throw new RuntimeException('آرشیو بکاپ شامل ورودی تکراری است: '.$name);
                }
                $seenNames[$name] = true;

                if (str_ends_with($name, '/')) {
                    continue;
                }

                if (($stat['encryption_method'] ?? null) !== ZipArchive::EM_AES_256) {
                    throw new RuntimeException('همه فایل‌های آرشیو بکاپ باید با AES-256 رمزنگاری شده باشند.');
                }

                if ($name === 'manifest.json') {
                    continue;
                }

                $expected = $entries[$name] ?? null;
                if (! is_array($expected)) {
                    throw new RuntimeException('فایلی خارج از موجودی رمزنگاری‌شده در آرشیو وجود دارد: '.$name);
                }

                $stream = $zip->getStream($name);
                if (! is_resource($stream)) {
                    throw new RuntimeException('یکی از فایل‌های رمزنگاری‌شده بکاپ قابل رمزگشایی نیست: '.$name);
                }

                $bytesRead = 0;
                $hash = hash_init('sha256');
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('خواندن کامل یکی از فایل‌های بکاپ ناموفق بود: '.$name);
                        }
                        if ($chunk === '') {
                            if (feof($stream)) {
                                break;
                            }
                            throw new RuntimeException('جریان یکی از فایل‌های بکاپ پیش از پایان متوقف شد: '.$name);
                        }
                        $bytesRead += strlen($chunk);
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }

                $actualChecksum = hash_final($hash);
                if (
                    $bytesRead !== (int) ($stat['size'] ?? -1)
                    || $bytesRead !== $expected['size_bytes']
                    || ! hash_equals($expected['sha256'], $actualChecksum)
                ) {
                    throw new RuntimeException('اندازه یا SHA-256 فایل رمزگشایی‌شده با موجودی بکاپ مطابقت ندارد: '.$name);
                }

                $seenEntries[$name] = true;
                if ($name === 'database/database.sql') {
                    $databaseBytes = $bytesRead;
                }
            }

            $expectedNames = array_keys($entries);
            $actualNames = array_keys($seenEntries);
            sort($expectedNames, SORT_STRING);
            sort($actualNames, SORT_STRING);

            if ($expectedNames !== $actualNames) {
                throw new RuntimeException('موجودی فایل‌های بکاپ با محتوای واقعی آرشیو مطابقت ندارد.');
            }

            if (! is_int($databaseBytes) || $databaseBytes < 1) {
                throw new RuntimeException('جریان کامل دیتابیس در آرشیو بکاپ موجود یا قابل رمزگشایی نیست.');
            }
        } finally {
            $zip->close();
        }
    }

    private function createArchive(string $archivePath, string $work, string $password): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ساخت فایل فشرده بکاپ ناموفق بود.');
        }
        if (! $zip->setPassword($password)) {
            $zip->close();
            throw new RuntimeException('تنظیم کلید رمزنگاری آرشیو بکاپ ناموفق بود.');
        }

        $manifest = [
            'format_version' => 2,
            'created_at' => now()->toIso8601String(),
            'application' => config('app.name'),
            'database_driver' => config('database.connections.'.config('database.default').'.driver', config('database.default')),
            'storage_policy' => 'farast-storage-core-v1',
        ];
        $entries = [];

        $this->addDirectory($zip, storage_path('app/private'), 'private', $entries);
        $this->addDirectory($zip, storage_path('app/public'), 'public', $entries);
        $this->addDirectory($zip, storage_path('app/remote-archive'), 'legacy-remote-archive', $entries);
        $this->addDatabaseDump($zip, $work, $entries);

        $managedCount = 0;
        StorageManagedFile::query()->whereIn('status', ['pending_transfer', 'transfer_error', 'delete_pending'])->whereNull('storage_volume_id')->orderBy('id')->chunkById(100, function ($files) use ($zip, $work, &$managedCount, &$entries) {
            foreach ($files as $file) {
                $disk = (string) data_get($file->metadata, 'staging_disk', 'private');
                $temporary = $work.'/staged-'.$file->id;
                $this->storage->downloadTo($disk, $file->file_key, $temporary);
                $entry = 'managed-staging/'.$file->id.'/'.basename($file->file_key);
                $this->addEncryptedFile($zip, $temporary, $entry, $entries);
                $managedCount++;
            }
        });
        StorageManagedFile::query()->whereIn('status', ['active', 'delete_pending'])->whereNotNull('storage_volume_id')->with('volume')->orderBy('id')->chunkById(100, function ($files) use ($zip, $work, &$managedCount, &$entries) {
            foreach ($files as $file) {
                if (! $file->volume) continue;
                $temporary = $work.'/managed-'.$file->id;
                $this->storage->downloadTo('storage_volume:'.$file->storage_volume_id, $file->file_key, $temporary);
                $safeKey = str_replace(['..', '\\'], '_', $file->file_key);
                $entry = 'managed/'.$file->storage_volume_id.'/'.$safeKey;
                $this->addEncryptedFile($zip, $temporary, $entry, $entries);
                $managedCount++;
            }
        });

        $manifest['managed_files_included'] = $managedCount;
        ksort($entries, SORT_STRING);
        $manifest['entries'] = $entries;
        $manifest['entry_count'] = count($entries);
        $manifest['inventory_hmac'] = hash_hmac('sha256', $this->canonicalizeEntries($entries), $password);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256)) {
            $zip->close();
            throw new RuntimeException('رمزنگاری Manifest بکاپ ناموفق بود.');
        }
        if (! $zip->close()) throw new RuntimeException('بستن آرشیو بکاپ ناموفق بود.');
    }

    private function addDirectory(ZipArchive $zip, string $directory, string $prefix, array &$entries): void
    {
        if (! is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || ! $file->isFile()) continue;
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/');
            $this->addEncryptedFile($zip, $file->getPathname(), $prefix.'/'.$relative, $entries);
        }
    }

    private function addEncryptedFile(ZipArchive $zip, string $path, string $entry, array &$entries): void
    {
        $size = filesize($path);
        $checksum = hash_file('sha256', $path);
        if (! is_int($size) || $size < 0 || ! is_string($checksum)) {
            throw new RuntimeException('محاسبه اندازه یا Checksum فایل بکاپ ناموفق بود: '.$entry);
        }
        if (! $zip->addFile($path, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('افزودن فایل رمزنگاری‌شده به بکاپ ناموفق بود: '.$entry);
        }

        $entries[$entry] = ['size_bytes' => $size, 'sha256' => $checksum];
    }

    private function addDatabaseDump(ZipArchive $zip, string $work, array &$entries): void
    {
        $connection = config('database.default');
        $config = config('database.connections.'.$connection, []);
        $driver = $config['driver'] ?? $connection;
        $dump = $work.'/database.sql';

        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? '');
            if ($database === '' || $database === ':memory:' || ! is_file($database) || ! copy($database, $dump)) {
                throw new RuntimeException('فایل پایگاه داده SQLite قابل کپی نیست.');
            }
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $command = [
                'mysqldump',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? '3306'),
                '--user='.(string) ($config['username'] ?? ''),
                '--single-transaction', '--quick', '--routines', '--triggers',
                '--add-drop-table', '--default-character-set=utf8mb4',
                '--result-file='.$dump,
                (string) ($config['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout(3600);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('تهیه خروجی MySQL ناموفق بود: '.mb_substr($process->getErrorOutput(), 0, 500));
            }
            if (! is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException('فایل خروجی MySQL خالی یا ایجادنشده است.');
            }
        } elseif ($driver === 'pgsql') {
            $command = [
                'pg_dump',
                '--host='.(string) ($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? '5432'),
                '--username='.(string) ($config['username'] ?? ''),
                '--format=plain', '--no-owner', '--no-privileges', '--clean', '--if-exists',
                '--file='.$dump,
                (string) ($config['database'] ?? ''),
            ];
            $process = new Process($command, base_path(), ['PGPASSWORD' => (string) ($config['password'] ?? '')]);
            $process->setTimeout(3600);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('تهیه خروجی PostgreSQL ناموفق بود: '.mb_substr($process->getErrorOutput(), 0, 500));
            }
            if (! is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException('فایل خروجی PostgreSQL خالی یا ایجادنشده است.');
            }
        } else {
            throw new RuntimeException('درایور پایگاه داده برای بکاپ پشتیبانی نشده است.');
        }

        $this->addEncryptedFile($zip, $dump, 'database/database.sql', $entries);
    }

    private function canonicalizeEntries(array $entries): string
    {
        ksort($entries, SORT_STRING);

        return json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}