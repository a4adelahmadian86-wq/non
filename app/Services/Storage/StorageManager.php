<?php

namespace App\Services\Storage;

use App\Models\StorageManagedFile;
use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StorageManager
{
    public const CRITICAL = 'critical';
    public const NON_CRITICAL = 'non_critical';

    public function __construct(private readonly S3CompatibleAdapter $s3) {}

    public function storeUploadedFile(
        UploadedFile $file,
        string $classification,
        string $sourceModule,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $productId = null,
        string $visibility = 'private'
    ): array {
        $classification = $classification === self::CRITICAL ? self::CRITICAL : self::NON_CRITICAL;
        $drive = $classification === self::CRITICAL ? 'iran_primary' : 'foreign_primary';
        $key = trim($sourceModule, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.strtolower($file->getClientOriginalExtension());
        $checksum = hash_file('sha256', $file->getRealPath());
        $volumes = $this->selectVolumes($drive, (int) $file->getSize());

        // Fail over only within the policy-selected drive. A critical file can
        // never spill into a foreign region, and a non-critical file cannot
        // consume the Iranian primary pool.
        foreach ($volumes as $volume) {
            try {
                $this->writeToVolume($volume, $key, $file->getRealPath());
                $this->verifyWrittenObject($volume, $key, $checksum);
                try {
                    return $this->register($volume, $key, $file, $checksum, $classification, $sourceModule, $sourceType, $sourceId, $productId, $visibility, 'active');
                } catch (\Throwable $exception) {
                    if ($volume->driver === 's3') $this->s3->delete($volume, $key);
                    else @unlink($this->localVolumePath($volume, $key));
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                try {
                    if ($volume->driver === 's3') $this->s3->delete($volume, $key);
                    elseif ($volume->driver === 'local') @unlink($this->localVolumePath($volume, $key));
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
                $volume->forceFill([
                    'status' => 'error',
                    'last_checked_at' => now(),
                    'metadata' => array_merge((array) $volume->metadata, ['last_error' => mb_substr($exception->getMessage(), 0, 400)]),
                ])->save();
                report($exception);
            }
        }

        // Safe local staging keeps the upload available while a target volume is unavailable.
        // Critical content is never routed to a foreign volume; non-critical content waits for foreign replication.
        if (! Storage::disk('private')->putFileAs(dirname($key), $file, basename($key))) {
            throw new RuntimeException('ذخیره موقت فایل در فضای امن محلی ناموفق بود.');
        }
        return $this->register(null, $key, $file, $checksum, $classification, $sourceModule, $sourceType, $sourceId, $productId, $visibility, 'pending_transfer', [
            'staging_disk' => 'private',
            'target_drive' => $drive,
        ]);
    }

    public function storeContents(
        string $contents,
        string $originalName,
        string $mime,
        string $classification,
        string $sourceModule,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $productId = null,
        string $visibility = 'private'
    ): array {
        $originalName = basename(str_replace('\\\\', '/', trim($originalName)));
        if ($originalName === '' || $originalName === '.' || $originalName === '..') {
            throw new RuntimeException('نام فایل برای ذخیره‌سازی معتبر نیست.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'farast-storage-content-');
        if (! is_string($temporaryPath)) {
            throw new RuntimeException('ساخت فایل موقت برای ذخیره محتوا ناموفق بود.');
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);
            if (! is_int($written) || $written !== strlen($contents)) {
                throw new RuntimeException('نوشتن کامل محتوای فایل موقت ناموفق بود.');
            }

            $upload = new UploadedFile(
                $temporaryPath,
                $originalName,
                $mime !== '' ? $mime : 'application/octet-stream',
                UPLOAD_ERR_OK,
                true
            );

            return $this->storeUploadedFile(
                $upload,
                $classification,
                $sourceModule,
                $sourceType,
                $sourceId,
                $productId,
                $visibility
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function adoptExistingPrivate(
        string $path,
        string $originalName,
        string $mime,
        int $size,
        string $sourceModule,
        string $sourceType,
        ?int $sourceId = null,
        ?int $productId = null,
        string $classification = self::NON_CRITICAL
    ): array {
        $disk = Storage::disk('private');
        if (! $disk->exists($path)) throw new RuntimeException('فایل موقت برای انتقال وجود ندارد.');
        $absolute = $disk->path($path);
        $upload = new UploadedFile($absolute, $originalName, $mime ?: null, UPLOAD_ERR_OK, true);
        $stored = $this->storeUploadedFile($upload, $classification, $sourceModule, $sourceType, $sourceId, $productId, 'private');
        return $stored;
    }

    public function bindReference(
        int $managedFileId,
        string $sourceType,
        int $sourceId,
        ?int $productId = null,
        string $sourceField = 'path'
    ): void {
        if (! in_array($sourceField, ['path', 'audio_path'], true)) {
            throw new RuntimeException('فیلد مرجع فایل در ماژول مبدأ پشتیبانی نمی‌شود.');
        }
        $file = StorageManagedFile::findOrFail($managedFileId);
        $metadata = (array) $file->metadata;
        $metadata['reference_pending'] = false;
        $metadata['source_field'] = $sourceField;
        $file->update([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'product_id' => $productId ?? $file->product_id,
            'metadata' => $metadata,
        ]);
    }

    public function exists(string $disk, string $path): bool
    {
        if (str_starts_with($disk, 'storage_volume:')) {
            $path = StoragePath::normalizeObjectKey($path);
            $volume = StorageVolume::find((int) substr($disk, 15));
            if (! $volume) return false;
            return $volume->driver === 'local'
                ? is_file($this->localVolumePath($volume, $path))
                : $this->s3->exists($volume, $path);
        }
        return Storage::disk($disk ?: 'private')->exists($path);
    }

    public function get(string $disk, string $path): string
    {
        if (str_starts_with($disk, 'storage_volume:')) {
            $path = StoragePath::normalizeObjectKey($path);
            $volume = StorageVolume::findOrFail((int) substr($disk, 15));
            if ($volume->driver === 'local') {
                $contents = @file_get_contents($this->localVolumePath($volume, $path));
                if ($contents === false) throw new RuntimeException('فایل در فضای ذخیره‌سازی موجود نیست.');
                return $contents;
            }
            return $this->s3->getContents($volume, $path);
        }
        return Storage::disk($disk ?: 'private')->get($path);
    }

    public function delete(string $disk, string $path): void
    {
        $volumeId = str_starts_with($disk, 'storage_volume:') ? (int) substr($disk, 15) : null;
        if ($volumeId) $path = StoragePath::normalizeObjectKey($path);
        $managed = StorageManagedFile::query()
            ->when($volumeId, fn ($q) => $q->where('storage_volume_id', $volumeId), fn ($q) => $q->whereNull('storage_volume_id'))
            ->where('file_key', $path)
            ->whereNotIn('status', ['deleted'])
            ->first();

        $snapshotInProgress = StorageBackupRun::query()
            ->where('source_name', 'snapshot_lock')
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subHours(8))
            ->exists();

        if ($managed) {
            // Persist the deletion intent before touching the external volume.
            // If the provider or the following DB commit fails, scheduled cleanup
            // can retry this idempotent operation without losing the managed record.
            $managed = DB::transaction(function () use ($managed, $snapshotInProgress): ?StorageManagedFile {
                $locked = StorageManagedFile::query()->lockForUpdate()->find($managed->id);
                if (! $locked || $locked->status === 'deleted') return null;

                $metadata = (array) $locked->metadata;
                $metadata['delete_requested_at'] ??= now()->toIso8601String();
                if ($snapshotInProgress) {
                    $metadata['delete_after_snapshot'] = true;
                }
                $locked->update(['status' => 'delete_pending', 'metadata' => $metadata]);

                return $locked->fresh();
            });

            if (! $managed || $snapshotInProgress) return;
        }

        if ($volumeId) {
            $volume = StorageVolume::find($volumeId);
            if ($volume) {
                if ($volume->driver === 'local') {
                    $localPath = $this->localVolumePath($volume, $path);
                    if (is_file($localPath) && ! @unlink($localPath)) {
                        throw new RuntimeException('حذف فایل از فضای محلی ناموفق بود.');
                    }
                } else {
                    $this->s3->delete($volume, $path);
                }
            }
        } else {
            $sourceDisk = Storage::disk($disk ?: 'private');
            if ($sourceDisk->exists($path) && ! $sourceDisk->delete($path)) {
                throw new RuntimeException('حذف فایل از فضای خصوصی ناموفق بود.');
            }
        }

        if ($managed) {
            DB::transaction(function () use ($managed, $volumeId): void {
                $locked = StorageManagedFile::query()->lockForUpdate()->find($managed->id);
                if (! $locked || $locked->status === 'deleted' || $locked->status !== 'delete_pending') return;

                if ($volumeId) {
                    DB::table('storage_volumes')->where('id', $volumeId)->update([
                        'used_bytes' => DB::raw('CASE WHEN used_bytes >= '.(int) $locked->size_bytes.' THEN used_bytes - '.(int) $locked->size_bytes.' ELSE 0 END'),
                        'object_count' => DB::raw('CASE WHEN object_count > 0 THEN object_count - 1 ELSE 0 END'),
                    ]);
                }

                $metadata = (array) $locked->metadata;
                unset($metadata['delete_after_snapshot'], $metadata['delete_requested_at']);
                $locked->update(['status' => 'deleted', 'metadata' => $metadata]);
            });
        }
    }

    public function cleanupDeferredDeletes(int $limit = 100): int
    {
        if (StorageBackupRun::query()->where('source_name', 'snapshot_lock')->where('status', 'running')->where('started_at', '>=', now()->subHours(8))->exists()) return 0;
        $files = StorageManagedFile::query()->where('status', 'delete_pending')->orderBy('id')->limit(max(1, $limit))->get();
        foreach ($files as $file) {
            $disk = (string) data_get($file->metadata, 'disk', $file->storage_volume_id ? 'storage_volume:'.$file->storage_volume_id : 'private');
            $this->delete($disk, $file->file_key);
        }
        return $files->count();
    }

    public function size(string $disk, string $path): int
    {
        if (str_starts_with($disk, 'storage_volume:')) {
            $path = StoragePath::normalizeObjectKey($path);
            $volume = StorageVolume::findOrFail((int) substr($disk, 15));

            if ($volume->driver === 'local') {
                $localPath = $this->localVolumePath($volume, $path);
                if (! is_file($localPath)) {
                    throw new RuntimeException('فایل در فضای ذخیره‌سازی موجود نیست.');
                }

                $size = filesize($localPath);
                if (! is_int($size)) {
                    throw new RuntimeException('اندازه فایل از فضای ذخیره‌سازی محلی قابل خواندن نیست.');
                }

                return $size;
            }

            return $this->s3->size($volume, $path);
        }

        $sourceDisk = Storage::disk($disk ?: 'private');
        if (! $sourceDisk->exists($path)) {
            throw new RuntimeException('فایل در فضای ذخیره‌سازی موجود نیست.');
        }

        $size = $sourceDisk->size($path);
        if (! is_int($size) || $size < 0) {
            throw new RuntimeException('اندازه فایل از فضای ذخیره‌سازی قابل خواندن نیست.');
        }

        return $size;
    }

    public function downloadTo(string $disk, string $path, string $target): void
    {
        if (str_starts_with($disk, 'storage_volume:')) {
            $path = StoragePath::normalizeObjectKey($path);
            $volume = StorageVolume::findOrFail((int) substr($disk, 15));
            if ($volume->driver === 'local') {
                $source = $this->localVolumePath($volume, $path);
                if (! is_file($source) || ! copy($source, $target)) throw new RuntimeException('دریافت فایل از فضای محلی ناموفق بود.');
                return;
            }
            $this->s3->downloadTo($volume, $path, $target);
            return;
        }

        $stream = Storage::disk($disk ?: 'private')->readStream($path);
        if (! is_resource($stream)) throw new RuntimeException('جریان فایل برای پشتیبان‌گیری در دسترس نیست.');
        $output = fopen($target, 'wb');
        try {
            if (stream_copy_to_stream($stream, $output) === false) throw new RuntimeException('ذخیره جریان فایل ناموفق بود.');
        } finally {
            fclose($stream);
            fclose($output);
        }
    }

    public function uploadBackup(StorageVolume $volume, string $key, string $localPath): void
    {
        $this->writeToVolume($volume, StoragePath::normalizeObjectKey($key), $localPath);
    }

    public function volumeDisk(StorageVolume $volume): string
    {
        return 'storage_volume:'.$volume->id;
    }

    private function selectVolumes(string $drive, int $bytes)
    {
        return StorageVolume::query()
            ->where('drive_key', $drive)
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [$bytes]))
            ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', $bytes))
            ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('object_count < file_limit'))
            ->orderBy('priority')
            ->orderBy('used_bytes')
            ->get();
    }

    private function localVolumePath(StorageVolume $volume, string $key): string
    {
        return StoragePath::localVolumePath($volume, $key);
    }

    private function writeToVolume(StorageVolume $volume, string $key, string $localPath): void
    {
        if ($volume->driver === 'local') {
            $target = $this->localVolumePath($volume, $key);
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0750, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('ساخت مسیر فایل روی فضای محلی هاست ناموفق بود.');
            }
            $temporary = dirname($target).'/.farast-upload-'.Str::uuid().'.tmp';
            try {
                if (! copy($localPath, $temporary)) throw new RuntimeException('کپی فایل موقت روی فضای محلی هاست ناموفق بود.');
                if (! rename($temporary, $target)) throw new RuntimeException('ثبت اتمیک فایل روی فضای محلی هاست ناموفق بود.');
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
            return;
        }
        if ($volume->driver !== 's3') throw new RuntimeException('آداپتور این نوع سرویس هنوز نصب نشده است.');
        $this->s3->putFile($volume, $key, $localPath);
    }

    private function verifyWrittenObject(StorageVolume $volume, string $key, string $expectedChecksum): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'farast-storage-verify');
        if (! is_string($temporary)) {
            throw new RuntimeException('ساخت فایل موقت برای تأیید صحت آپلود ناموفق بود.');
        }

        try {
            $this->downloadTo($this->volumeDisk($volume), $key, $temporary);
            $actualChecksum = hash_file('sha256', $temporary);
            if (! is_string($actualChecksum) || ! hash_equals($expectedChecksum, $actualChecksum)) {
                throw new RuntimeException('Checksum فایل ذخیره‌شده با فایل اصلی مطابقت ندارد.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function register(
        ?StorageVolume $volume,
        string $key,
        UploadedFile $file,
        string $checksum,
        string $classification,
        string $sourceModule,
        ?string $sourceType,
        ?int $sourceId,
        ?int $productId,
        string $visibility,
        string $status,
        array $metadata = []
    ): array {
        return DB::transaction(function () use ($volume, $key, $file, $checksum, $classification, $sourceModule, $sourceType, $sourceId, $productId, $visibility, $status, $metadata): array {
            $disk = $volume ? $this->volumeDisk($volume) : 'private';
            $record = StorageManagedFile::create([
                'storage_volume_id' => $volume?->id,
                'drive_key' => $classification === self::CRITICAL ? 'iran_primary' : 'foreign_primary',
                'file_key' => $key,
                'file_key_hash' => hash('sha256', $key),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'checksum' => $checksum,
                'classification' => $classification,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'product_id' => $productId,
                'visibility' => $visibility,
                'status' => $status,
                'metadata' => array_merge($metadata, ['disk' => $disk, 'original_extension' => $file->getClientOriginalExtension(), 'reference_pending' => true]),
            ]);

            if ($volume) {
                $volume->increment('used_bytes', (int) $file->getSize());
                $volume->increment('object_count');
            }

            return [
                'managed_file_id' => $record->id,
                'disk' => $disk,
                'path' => $key,
                'volume_id' => $volume?->id,
                'size_bytes' => (int) $file->getSize(),
                'mime' => $file->getMimeType(),
                'sha256' => $checksum,
                'status' => $status,
            ];
        });
    }
}