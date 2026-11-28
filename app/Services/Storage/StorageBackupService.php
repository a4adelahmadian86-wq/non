<?php

namespace App\Services\Storage;

use App\Models\StorageBackupRun;
use App\Models\StorageBackupObject;
use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function runWeekly(string $backupType = 'weekly'): array
    {
        if (! in_array($backupType, ['weekly', 'manual'], true)) {
            throw new RuntimeException('نوع درخواست بکاپ پشتیبانی نمی‌شود.');
        }

        // Prevent concurrent snapshots from racing volume capacity accounting and uploads.
        // The worker hard timeout is four hours; the five-hour lease matches the stale-run recovery threshold.
        $lock = Cache::lock('farast:backup:execution', 18000);
        if (! $lock->get()) {
            throw new \RuntimeException('A backup operation is already running.');
        }

        try {
            return $this->runWeeklyLocked($backupType);
        } finally {
            $lock->release();
        }
    }

    private function runWeeklyLocked(string $backupType): array
    {
        // Never read every source object unless at least one backup destination is configured.
        // This preflight must happen before archive creation, because creating the archive downloads
        // all managed objects (including objects stored on metered/free-tier primary volumes).
        $targets = $this->availableBackupTargets();

        if ($targets->isEmpty()) {
            StorageBackupRun::create([
                'backup_type' => $backupType,
                'source_name' => 'application',
                'status' => 'failed',
                'error_message' => 'هیچ مقصد سالمی برای بکاپ پیکربندی نشده است؛ برای جلوگیری از مصرف ترافیک، آرشیو ساخته نشد.',
                'completed_at' => now(),
                'metadata' => ['archive_skipped' => true, 'reason' => 'no_healthy_destination'],
            ]);

            throw new RuntimeException('هیچ مقصد سالمی برای بکاپ پیکربندی نشده است؛ هیچ فایل مبدأ خوانده نشد.');
        }

        if ((bool) config('storage_management.backup.use_incremental_format', true)) {
            return $this->runIncrementalLocked($backupType, $targets);
        }

        $hasIranPrimarySources = StorageManagedFile::query()
            ->whereIn('status', ['active', 'delete_pending'])
            ->whereNotNull('storage_volume_id')
            ->whereHas('volume', fn ($query) => $query->where('drive_key', 'iran_primary'))
            ->exists();

        if ($hasIranPrimarySources && ! (bool) config('storage_management.backup.allow_iran_primary_full_source_reads', false)) {
            StorageBackupRun::create([
                'backup_type' => $backupType,
                'source_name' => 'application',
                'status' => 'failed',
                'error_message' => 'بکاپ کامل از iran_primary به‌صورت پیش‌فرض غیرفعال است تا ترافیک رایگان آروان مصرف نشود؛ ابتدا بکاپ افزایشی یا مجوز صریح لازم است.',
                'completed_at' => now(),
                'metadata' => ['archive_skipped' => true, 'reason' => 'iran_primary_full_read_disabled'],
            ]);

            throw new RuntimeException('بکاپ کامل از iran_primary غیرفعال است؛ هیچ فایل مبدأ خوانده نشد.');
        }

        $backupKey = (string) config('storage_management.backup.encryption_key', '');
        if ($backupKey === '') throw new RuntimeException('برای بکاپ رمزنگاری‌شده، FARAST_BACKUP_ENCRYPTION_KEY باید تنظیم شود.');
        if (! class_exists(ZipArchive::class)) throw new RuntimeException('افزونه ZipArchive برای ساخت بکاپ لازم است.');

        $work = storage_path('app/backup-work/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) throw new RuntimeException('ساخت فضای موقت بکاپ ناموفق بود.');
        $archivePath = $work.'/farast-'.$backupType.'.zip';
        $results = [];
        $snapshotLock = StorageBackupRun::create([
            'backup_type' => $backupType,
            'source_name' => 'snapshot_lock',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['lock_only' => true],
        ]);

        try {
            $this->createArchive($archivePath, $work, $backupKey);
            $snapshotLock->update(['status' => 'completed', 'completed_at' => now()]);
            $archiveSize = (int) filesize($archivePath);
            $targets = $targets->filter(function (StorageVolume $target) use ($archiveSize): bool {
                return ($target->capacity_bytes === null || ((int) $target->capacity_bytes - (int) $target->used_bytes) >= $archiveSize)
                    && ($target->max_file_bytes === null || (int) $target->max_file_bytes >= $archiveSize)
                    && ($target->file_limit === null || (int) $target->object_count < (int) $target->file_limit);
            })->values();

            if ($targets->isEmpty()) {
                StorageBackupRun::create([
                    'backup_type' => $backupType, 'source_name' => 'application',
                    'status' => 'failed',
                    'error_message' => 'فضای خالی یا محدودیت مقصد برای آرشیو کافی نیست؛ آرشیو ساخته شد اما به مقصدی ارسال نشد.',
                    'completed_at' => now(),
                    'metadata' => ['reason' => 'insufficient_destination_capacity'],
                ]);
                throw new RuntimeException('فضای خالی یا محدودیت هیچ مقصد بکاپی برای آرشیو کافی نیست.');
            }

            $checksum = hash_file('sha256', $archivePath);
            foreach ($targets as $target) {
                $run = StorageBackupRun::create([
                    'storage_volume_id' => $target->id,
                    'backup_type' => $backupType,
                    'source_name' => 'application',
                    'status' => 'running',
                    'started_at' => now(),
                    'size_bytes' => (int) filesize($archivePath),
                    'checksum' => $checksum,
                ]);
                $objectKey = $backupType.'/'.now()->format('Y/m/d').'/farast-'.now()->format('Ymd-His').'-'.$run->id.'.zip';
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
                    $this->pruneOldBackups($target, $run);
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

    private function runIncrementalLocked(string $backupType, $targets): array
    {
        $backupKey = (string) config('storage_management.backup.encryption_key', '');
        if (strlen($backupKey) < 32) {
            throw new RuntimeException('کلید رمزنگاری بکاپ باید حداقل ۳۲ بایت باشد.');
        }
        if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            throw new RuntimeException('افزونه Sodium برای بکاپ افزایشی امن در دسترس نیست.');
        }

        $target = $targets->first();
        if (! $target) {
            throw new RuntimeException('مقصد خارجی سالم برای بکاپ افزایشی وجود ندارد.');
        }

        $work = storage_path('app/backup-work/'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException('ساخت فضای موقت بکاپ افزایشی ناموفق بود.');
        }

        $startedAt = now();
        $snapshotLock = StorageBackupRun::create([
            'backup_type' => $backupType,
            'source_name' => 'snapshot_lock',
            'status' => 'running',
            'started_at' => $startedAt,
            'metadata' => ['lock_only' => true, 'format_version' => 3],
        ]);

        $uploadedManifestKey = null;
        try {
            $corePath = $work.'/farast-core.zip';
            $this->createArchive($corePath, $work, $backupKey, false);
            $objectIds = [];
            $coreObject = $this->storeBackupObject($target, $corePath, $backupKey, $work, [
                'source_kind' => 'local_snapshot_core',
            ]);
            $objectIds[] = (int) $coreObject->id;

            $entries = [];
            $sourceReadBytes = 0;
            $perRunSourceReadLimit = max(1, (int) config('storage_management.backup.max_iran_source_read_bytes_per_run', 536870912));

            StorageManagedFile::query()
                ->whereIn('status', ['pending_transfer', 'transfer_error', 'delete_pending'])
                ->whereNull('storage_volume_id')
                ->orderBy('id')
                ->chunkById(100, function ($files) use ($target, $backupKey, $work, &$entries, &$objectIds, &$sourceReadBytes, $perRunSourceReadLimit): void {
                    foreach ($files as $file) {
                        $disk = (string) data_get($file->metadata, 'staging_disk', 'private');
                        $temporary = $work.'/staged-'.$file->id;
                        $this->storage->downloadTo($disk, $file->file_key, $temporary);
                        $object = $this->storeBackupObject($target, $temporary, $backupKey, $work, [
                            'source_kind' => 'local_staging',
                        ]);
                        $entry = 'managed-staging/'.$file->id.'/'.basename($file->file_key);
                        $entries[$entry] = $this->backupObjectManifestEntry($object);
                        $objectIds[] = (int) $object->id;
                        @unlink($temporary);
                    }
                });

            StorageManagedFile::query()
                ->whereIn('status', ['active', 'delete_pending'])
                ->whereNotNull('storage_volume_id')
                ->with('volume')
                ->orderBy('id')
                ->chunkById(100, function ($files) use ($target, $backupKey, $work, &$entries, &$objectIds, &$sourceReadBytes, $perRunSourceReadLimit, $monthlySourceReadLimit): void {
                    foreach ($files as $file) {
                        if (! $file->volume) {
                            throw new RuntimeException('فایل مدیریت‌شده به فضای ذخیره‌سازی معتبر متصل نیست: '.$file->id);
                        }

                        $checksum = strtolower((string) $file->checksum);
                        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
                            throw new RuntimeException('فایل مدیریت‌شده فاقد SHA-256 معتبر است؛ بکاپ بدون خواندن کامل مبدأ متوقف شد: '.$file->id);
                        }

                        $object = $this->findReusableBackupObject($target, $checksum);
                        if (! $object) {
                            $size = max(0, (int) $file->size_bytes);
                            if ($file->volume->drive_key === 'iran_primary') {
                                if ($sourceReadBytes + $size > $perRunSourceReadLimit) {
                                    throw new RuntimeException('سقف امن خواندن مبدأ ایران برای این نوبت یا ماه تکمیل شده است؛ ادامه بکاپ به اجرای بعدی موکول شد.');
                                }
                                $sourceReadBytes += $size;
                            }

                            $this->reserveBackupUsage($file->volume, 'class_b', 1, $file->volume->drive_key === 'iran_primary' ? $size : 0);
                            $temporary = $work.'/managed-source-'.$file->id;
                            $this->storage->downloadTo('storage_volume:'.$file->storage_volume_id, $file->file_key, $temporary);
                            $actualSize = @filesize($temporary);
                            $actualChecksum = @hash_file('sha256', $temporary);
                            if (! is_int($actualSize) || $actualSize !== $size
                                || ! is_string($actualChecksum) || ! hash_equals($checksum, $actualChecksum)) {
                                @unlink($temporary);
                                throw new RuntimeException('اندازه یا SHA-256 فایل مبدأ با رکورد پایگاه داده مطابقت ندارد: '.$file->id);
                            }

                            $object = $this->storeBackupObject($target, $temporary, $backupKey, $work, [
                                'source_kind' => 'managed_remote',
                                'source_drive_key' => (string) $file->volume->drive_key,
                                'source_volume_id' => (int) $file->storage_volume_id,
                                'source_read_bytes' => $file->volume->drive_key === 'iran_primary' ? $size : 0,
                            ], false);
                            @unlink($temporary);
                        }

                        $safeKey = str_replace(['..', '\\'], '_', $file->file_key);
                        $entry = 'managed/'.$file->storage_volume_id.'/'.$safeKey;
                        $entries[$entry] = $this->backupObjectManifestEntry($object);
                        $objectIds[] = (int) $object->id;
                    }
                });

            $objectIds = array_values(array_unique($objectIds));
            ksort($entries, SORT_STRING);
            $manifest = [
                'format_version' => 3,
                'created_at' => now()->toIso8601String(),
                'application' => config('app.name'),
                'database_driver' => config('database.connections.'.config('database.default').'.driver', config('database.default')),
                'storage_policy' => 'farast-storage-core-v1',
                'core' => $this->backupObjectManifestEntry($coreObject),
                'managed_entries' => $entries,
                'entry_count' => count($entries) + 1,
                'source_read_bytes' => $sourceReadBytes,
            ];
            $inventory = [
                'core' => $manifest['core'],
                'managed_entries' => $entries,
                'database_driver' => $manifest['database_driver'],
            ];
            $manifest['inventory_hmac'] = hash_hmac(
                'sha256',
                json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $backupKey
            );

            $manifestPlainPath = $work.'/manifest.json';
            $manifestEncryptedPath = $work.'/manifest.enc';
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifestPlainPath, $manifestJson, LOCK_EX) !== strlen($manifestJson)) {
                throw new RuntimeException('نوشتن Manifest بکاپ افزایشی ناموفق بود.');
            }
            $manifestMeta = app(StorageBackupObjectCipher::class)->encryptFile($manifestPlainPath, $manifestEncryptedPath, $backupKey);
            $manifestChecksum = hash_file('sha256', $manifestEncryptedPath);
            if (! is_string($manifestChecksum)) {
                throw new RuntimeException('محاسبه Checksum Manifest بکاپ ناموفق بود.');
            }

            $uploadedManifestKey = 'manifests/'.$backupType.'/'.now()->format('Y/m/d').'/'.Str::uuid().'.enc';
            $this->assertBackupTargetCapacity($target, $manifestMeta['encrypted_size_bytes']);
            $this->reserveBackupUsage($target, 'class_a');
            $this->storage->uploadBackup($target, $uploadedManifestKey, $manifestEncryptedPath);
            $this->reserveBackupUsage($target, 'class_b');
            if ($this->storage->size($this->storage->volumeDisk($target), $uploadedManifestKey) !== $manifestMeta['encrypted_size_bytes']) {
                throw new RuntimeException('اندازه Manifest پس از انتقال با فایل محلی مطابقت ندارد.');
            }

            $run = DB::transaction(function () use ($target, $backupType, $startedAt, $uploadedManifestKey, $manifestMeta, $manifestChecksum, $objectIds, $manifest): StorageBackupRun {
                $run = StorageBackupRun::create([
                    'storage_volume_id' => $target->id,
                    'backup_type' => $backupType,
                    'source_name' => 'application',
                    'status' => 'completed',
                    'object_key' => $uploadedManifestKey,
                    'size_bytes' => $manifestMeta['encrypted_size_bytes'],
                    'checksum' => $manifestChecksum,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'metadata' => [
                        'format_version' => 3,
                        'manifest_plaintext_size' => $manifestMeta['size_bytes'],
                        'manifest_plaintext_sha256' => $manifestMeta['sha256'],
                        'encrypted' => true,
                        'encryption' => 'Sodium secretstream XChaCha20-Poly1305',
                        'object_count' => count($objectIds),
                        'source_read_bytes' => $manifest['source_read_bytes'],
                    ],
                ]);
                $run->backupObjects()->sync($objectIds);
                $lockedTarget = StorageVolume::query()->lockForUpdate()->findOrFail($target->id);
                $lockedTarget->update([
                    'used_bytes' => (int) $lockedTarget->used_bytes + (int) $manifestMeta['encrypted_size_bytes'],
                    'object_count' => (int) $lockedTarget->object_count + 1,
                ]);

                return $run;
            });

            $snapshotLock->update(['status' => 'completed', 'completed_at' => now()]);
            $this->pruneOldBackups($target, $run);

            return [[
                'volume_id' => $target->id,
                'status' => 'completed',
                'object_key' => $uploadedManifestKey,
                'format_version' => 3,
                'object_count' => count($objectIds),
                'source_read_bytes' => $sourceReadBytes,
            ]];
        } catch (\Throwable $exception) {
            if (is_string($uploadedManifestKey)) {
                try {
                    $this->storage->delete($this->storage->volumeDisk($target), $uploadedManifestKey);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $snapshotLock->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        } finally {
            $this->removeDirectory($work);
        }
    }

    private function findReusableBackupObject(StorageVolume $target, string $checksum): ?StorageBackupObject
    {
        $object = StorageBackupObject::query()
            ->where('storage_volume_id', $target->id)
            ->where('sha256', $checksum)
            ->where('status', 'verified')
            ->first();

        if (! $object) {
            return null;
        }

        $this->reserveBackupUsage($target, 'class_b');
        if (! $this->storage->exists($this->storage->volumeDisk($target), $object->object_key)) {
            $metadata = (array) $object->metadata;
            $metadata['remote_object_missing_at'] = now()->toIso8601String();
            $object->update(['status' => 'missing', 'metadata' => $metadata]);

            return null;
        }

        return $object;
    }

    private function storeBackupObject(
        StorageVolume $target,
        string $sourcePath,
        string $backupKey,
        string $work,
        array $metadata,
        bool $allowReuse = true
    ): StorageBackupObject {
        $checksum = @hash_file('sha256', $sourcePath);
        $size = @filesize($sourcePath);
        if (! is_string($checksum) || ! is_int($size) || $size < 0) {
            throw new RuntimeException('محاسبه هویت فایل برای بکاپ افزایشی ناموفق بود.');
        }

        $existing = $allowReuse ? $this->findReusableBackupObject($target, $checksum) : null;
        if ($existing) {
            if ((int) $existing->size_bytes !== $size) {
                throw new RuntimeException('یک SHA-256 با اندازه متفاوت در مخزن بکاپ ثبت شده است.');
            }

            return $existing;
        }

        $encryptedPath = $work.'/blob-'.Str::uuid().'.enc';
        $encryptedMeta = app(StorageBackupObjectCipher::class)->encryptFile($sourcePath, $encryptedPath, $backupKey);
        $objectKey = 'objects/'.substr($checksum, 0, 2).'/'.Str::uuid().'.enc';
        $this->assertBackupTargetCapacity($target, $encryptedMeta['encrypted_size_bytes']);
        try {
            $this->reserveBackupUsage($target, 'class_a');
            $this->storage->uploadBackup($target, $objectKey, $encryptedPath);
            $this->reserveBackupUsage($target, 'class_b');
            $remoteSize = $this->storage->size($this->storage->volumeDisk($target), $objectKey);
            if ($remoteSize !== $encryptedMeta['encrypted_size_bytes']) {
                throw new RuntimeException('اندازه Blob بکاپ پس از انتقال با نسخه محلی مطابقت ندارد.');
            }
        } catch (\Throwable $uploadException) {
            try {
                $this->storage->delete($this->storage->volumeDisk($target), $objectKey);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
            throw $uploadException;
        }

        try {
            return DB::transaction(function () use ($target, $checksum, $size, $objectKey, $encryptedMeta, $metadata): StorageBackupObject {
                $object = StorageBackupObject::query()
                    ->lockForUpdate()
                    ->where('storage_volume_id', $target->id)
                    ->where('sha256', $checksum)
                    ->first();
                $oldEncryptedSize = $object ? (int) $object->encrypted_size_bytes : 0;
                $isNew = ! $object;
                $object ??= new StorageBackupObject();
                $object->fill([
                    'storage_volume_id' => $target->id,
                    'sha256' => $checksum,
                    'size_bytes' => $size,
                    'object_key' => $objectKey,
                    'encrypted_size_bytes' => $encryptedMeta['encrypted_size_bytes'],
                    'status' => 'verified',
                    'verified_at' => now(),
                    'metadata' => array_merge($metadata, [
                        'cipher' => 'sodium-secretstream-xchacha20poly1305-v1',
                    ]),
                ])->save();

                $lockedTarget = StorageVolume::query()->lockForUpdate()->findOrFail($target->id);
                $lockedTarget->update([
                    'used_bytes' => max(0, (int) $lockedTarget->used_bytes - $oldEncryptedSize) + (int) $encryptedMeta['encrypted_size_bytes'],
                    'object_count' => (int) $lockedTarget->object_count + ($isNew ? 1 : 0),
                ]);

                return $object;
            });
        } catch (\Throwable $exception) {
            try {
                $this->storage->delete($this->storage->volumeDisk($target), $objectKey);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
            throw $exception;
        } finally {
            @unlink($encryptedPath);
        }
    }

    private function assertBackupTargetCapacity(StorageVolume $target, int $additionalBytes): void
    {
        $configuredLimit = max(1, (int) config('storage_management.backup.max_target_storage_bytes', 4294967296));
        $providerLimit = $target->capacity_bytes !== null ? (int) $target->capacity_bytes : $configuredLimit;
        $hardLimit = min($configuredLimit, $providerLimit);
        $freshTarget = $target->fresh();
        $current = (int) $freshTarget->used_bytes;
        $maxObjectBytes = $freshTarget->max_file_bytes !== null
            ? min($hardLimit, (int) $freshTarget->max_file_bytes)
            : $hardLimit;
        if ($additionalBytes < 0 || $additionalBytes > $maxObjectBytes
            || $current + $additionalBytes > $hardLimit
            || ($freshTarget->file_limit !== null && (int) $freshTarget->object_count >= (int) $freshTarget->file_limit)) {
            throw new RuntimeException('سقف امن فضای بکاپ رایگان یا محدودیت تعداد فایل تکمیل شده است؛ برای جلوگیری از هزینه، آپلود جدید متوقف شد.');
        }
    }

    private function reserveBackupUsage(
        StorageVolume $volume,
        string $operationClass,
        int $operationCount = 1,
        int $sourceReadBytes = 0
    ): void {
        if (! in_array($operationClass, ['class_a', 'class_b'], true) || $operationCount < 0 || $sourceReadBytes < 0) {
            throw new RuntimeException('رزرو مصرف بکاپ نامعتبر است.');
        }

        $periodStart = now()->startOfMonth()->toDateString();
        DB::transaction(function () use ($volume, $operationClass, $operationCount, $sourceReadBytes, $periodStart): void {
            DB::table('storage_backup_usage_periods')->insertOrIgnore([
                'storage_volume_id' => $volume->id,
                'period_start' => $periodStart,
                'class_a_operations' => 0,
                'class_b_operations' => 0,
                'source_read_bytes' => 0,
                'uploaded_bytes' => 0,
                'downloaded_bytes' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = DB::table('storage_backup_usage_periods')
                ->where('storage_volume_id', $volume->id)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                throw new RuntimeException('دفتر ثبت مصرف ماهانه بکاپ در دسترس نیست.');
            }

            $nextA = (int) $usage->class_a_operations + ($operationClass === 'class_a' ? $operationCount : 0);
            $nextB = (int) $usage->class_b_operations + ($operationClass === 'class_b' ? $operationCount : 0);
            $nextSourceBytes = (int) $usage->source_read_bytes + $sourceReadBytes;

            if ($volume->drive_key === 'foreign_backup') {
                $maxA = max(1, (int) config('storage_management.backup.max_class_a_ops_per_month', 8000));
                $maxB = max(1, (int) config('storage_management.backup.max_class_b_ops_per_month', 80000));
                if ($nextA > $maxA || $nextB > $maxB) {
                    throw new RuntimeException('سقف امن عملیات ماهانه فضای بکاپ خارجی تکمیل شده است؛ عملیات متوقف شد.');
                }
            } elseif ($volume->drive_key === 'iran_primary') {
                $maxOps = max(1, (int) config('storage_management.backup.max_iran_ops_per_month', 7000));
                if ($nextA + $nextB > $maxOps) {
                    throw new RuntimeException('سقف امن عملیات ماهانه فضای اصلی ایران تکمیل شده است؛ عملیات متوقف شد.');
                }
                $maxSourceBytes = max(1, (int) config('storage_management.backup.max_iran_source_read_bytes_per_month', 5368709120));
                if ($nextSourceBytes > $maxSourceBytes) {
                    throw new RuntimeException('سقف امن ترافیک خواندن ماهانه از فضای اصلی ایران تکمیل شده است؛ عملیات متوقف شد.');
                }
            }

            DB::table('storage_backup_usage_periods')
                ->where('id', $usage->id)
                ->update([
                    'class_a_operations' => $nextA,
                    'class_b_operations' => $nextB,
                    'source_read_bytes' => $nextSourceBytes,
                    'updated_at' => now(),
                ]);
        });
    }

    private function backupObjectManifestEntry(StorageBackupObject $object): array
    {
        return [
            'id' => (int) $object->id,
            'object_key' => (string) $object->object_key,
            'sha256' => (string) $object->sha256,
            'size_bytes' => (int) $object->size_bytes,
            'encrypted_size_bytes' => (int) $object->encrypted_size_bytes,
        ];
    }

    private function availableBackupTargets()
    {
        $targets = StorageVolume::query()
            ->where('drive_key', 'foreign_backup')
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereColumn('capacity_bytes', '>', 'used_bytes'))
            ->where(fn ($q) => $q->whereNull('file_limit')->orWhereColumn('object_count', '<', 'file_limit'))
            ->orderBy('priority')
            ->get();

        if ((bool) config('storage_management.backup.enable_iran_replica', false)) {
            $targets = $targets->concat(StorageVolume::query()
                ->where('drive_key', 'iran_backup')
                ->where('is_active', true)
                ->where('status', 'healthy')
                ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereColumn('capacity_bytes', '>', 'used_bytes'))
                ->where(fn ($q) => $q->whereNull('file_limit')->orWhereColumn('object_count', '<', 'file_limit'))
                ->orderBy('priority')
                ->get());
        }

        // The project explicitly forbids Arvan as a backup destination.
        return $targets->reject(fn (StorageVolume $volume): bool => $this->isArvanEndpoint($volume))->values();
    }

    private function isArvanEndpoint(StorageVolume $volume): bool
    {
        $endpoint = (string) ($volume->endpoint ?: data_get($volume->credentials, 'endpoint', ''));
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        return $host === 'arvanstorage.ir' || str_ends_with($host, '.arvanstorage.ir');
    }

    private function pruneOldBackups(StorageVolume $target, StorageBackupRun $currentRun): void
    {
        $keepLatest = max(1, min(10, (int) config('storage_management.backup.keep_latest', 1)));
        $oldRuns = StorageBackupRun::query()
            ->where('storage_volume_id', $target->id)
            ->where('source_name', 'application')
            ->where('backup_type', $currentRun->backup_type)
            ->where('status', 'completed')
            ->where('id', '!=', $currentRun->id)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->slice($keepLatest - 1);

        foreach ($oldRuns as $oldRun) {
            if (! $oldRun->object_key) {
                continue;
            }

            try {
                // Delete the old manifest first. Its referenced blobs remain protected by pivot rows.
                $this->storage->delete($this->storage->volumeDisk($target), $oldRun->object_key);
                $oldObjects = $oldRun->backupObjects()->get();

                DB::transaction(function () use ($target, $oldRun): void {
                    $lockedVolume = StorageVolume::query()->lockForUpdate()->find($target->id);
                    $lockedRun = StorageBackupRun::query()->lockForUpdate()->find($oldRun->id);

                    if (! $lockedVolume || ! $lockedRun || $lockedRun->status !== 'completed') {
                        return;
                    }

                    $metadata = (array) $lockedRun->metadata;
                    $metadata['pruned_object_key'] = $lockedRun->object_key;
                    $metadata['pruned_at'] = now()->toIso8601String();

                    $lockedRun->backupObjects()->detach();
                    $lockedVolume->update([
                        'used_bytes' => max(0, (int) $lockedVolume->used_bytes - (int) $lockedRun->size_bytes),
                        'object_count' => max(0, (int) $lockedVolume->object_count - 1),
                    ]);
                    $lockedRun->update([
                        'status' => 'pruned',
                        'object_key' => null,
                        'metadata' => $metadata,
                    ]);
                });

                foreach ($oldObjects as $oldObject) {
                    if ($oldObject->runs()->exists()) {
                        continue;
                    }

                    try {
                        $this->storage->delete($this->storage->volumeDisk($target), $oldObject->object_key);
                        DB::transaction(function () use ($target, $oldObject): void {
                            $lockedObject = StorageBackupObject::query()->lockForUpdate()->find($oldObject->id);
                            if (! $lockedObject || $lockedObject->runs()->exists()) {
                                return;
                            }

                            $lockedVolume = StorageVolume::query()->lockForUpdate()->find($target->id);
                            if ($lockedVolume) {
                                $lockedVolume->update([
                                    'used_bytes' => max(0, (int) $lockedVolume->used_bytes - (int) $lockedObject->encrypted_size_bytes),
                                    'object_count' => max(0, (int) $lockedVolume->object_count - 1),
                                ]);
                            }
                            $lockedObject->delete();
                        });
                    } catch (\Throwable $blobException) {
                        report($blobException);
                        try {
                            $metadata = (array) $oldObject->metadata;
                            $metadata['cleanup_pending'] = true;
                            $oldObject->update(['metadata' => $metadata]);
                        } catch (\Throwable $recordException) {
                            report($recordException);
                        }
                        $this->markRetentionCleanupPending($currentRun);
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
                try {
                    $metadata = (array) $oldRun->metadata;
                    $metadata['retention_cleanup_pending'] = true;
                    $oldRun->update(['metadata' => $metadata]);
                } catch (\Throwable $recordException) {
                    report($recordException);
                }

                $this->markRetentionCleanupPending($currentRun);
            }
        }
    }

    private function markRetentionCleanupPending(StorageBackupRun $currentRun): void
    {
        try {
            $currentMetadata = (array) $currentRun->fresh()->metadata;
            $currentMetadata['retention_cleanup_pending'] = true;
            $currentRun->update([
                'metadata' => $currentMetadata,
                'error_message' => 'نسخه جدید بکاپ تأیید شد، اما پاک‌سازی داده‌های قدیمی نیازمند بررسی است.',
            ]);
        } catch (\Throwable $recordException) {
            report($recordException);
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

            $formatVersion = is_array($manifest) ? (int) ($manifest['format_version'] ?? 1) : 0;
            if (! is_array($manifest) || ! in_array($formatVersion, [1, 2], true) || empty($manifest['database_driver'])) {
                throw new RuntimeException('Manifest رمزنگاری‌شده بکاپ ناقص یا ناسازگار است.');
            }

            $entries = [];
            if ($formatVersion === 2) {
                $entries = $manifest['entries'] ?? null;
                if (
                    ! is_array($entries)
                    || count($entries) < 1
                    || (int) ($manifest['entry_count'] ?? -1) !== count($entries)
                ) {
                    throw new RuntimeException('موجودی فایل‌های بکاپ ناقص است.');
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

                $expected = $formatVersion === 2 ? ($entries[$name] ?? null) : null;
                if ($formatVersion === 2 && ! is_array($expected)) {
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

                if ($bytesRead !== (int) ($stat['size'] ?? -1)) {
                    throw new RuntimeException('اندازه فایل رمزگشایی‌شده با ZIP مطابقت ندارد: '.$name);
                }

                if ($formatVersion === 2) {
                    $actualChecksum = hash_final($hash);
                    if (
                        $bytesRead !== $expected['size_bytes']
                        || ! hash_equals($expected['sha256'], $actualChecksum)
                    ) {
                        throw new RuntimeException('اندازه یا SHA-256 فایل رمزگشایی‌شده با موجودی بکاپ مطابقت ندارد: '.$name);
                    }
                    $seenEntries[$name] = true;
                }

                if ($name === 'database/database.sql') {
                    $databaseBytes = $bytesRead;
                }
            }

            if ($formatVersion === 2) {
                $expectedNames = array_keys($entries);
                $actualNames = array_keys($seenEntries);
                sort($expectedNames, SORT_STRING);
                sort($actualNames, SORT_STRING);
                if ($expectedNames !== $actualNames) {
                    throw new RuntimeException('موجودی فایل‌های بکاپ با محتوای واقعی آرشیو مطابقت ندارد.');
                }
            }

            if (! is_int($databaseBytes) || $databaseBytes < 1) {
                throw new RuntimeException('جریان کامل دیتابیس در آرشیو بکاپ موجود یا قابل رمزگشایی نیست.');
            }
        } finally {
            $zip->close();
        }
    }

    private function createArchive(string $archivePath, string $work, string $password, bool $includeManaged = true): void
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

        if ($includeManaged) {
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
        }
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