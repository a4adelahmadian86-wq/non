<?php

namespace App\Services\Storage;

use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Http\UploadedFile;
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
        $volume = $this->selectVolume($drive, (int) $file->getSize());

        if ($volume) {
            try {
                $this->writeToVolume($volume, $key, $file->getRealPath());
                try {
                    return $this->register($volume, $key, $file, $checksum, $classification, $sourceModule, $sourceType, $sourceId, $productId, $visibility, 'active');
                } catch (\Throwable $exception) {
                    if ($volume->driver === 's3') $this->s3->delete($volume, $key);
                    else @unlink($this->localVolumePath($volume, $key));
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $volume->forceFill(['status' => 'error', 'last_checked_at' => now(), 'metadata' => array_merge((array) $volume->metadata, ['last_error' => mb_substr($exception->getMessage(), 0, 400)])])->save();
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

    public function exists(string $disk, string $path): bool
    {
        if (str_starts_with($disk, 'storage_volume:')) {
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
        $volumeId = null;
        if (str_starts_with($disk, 'storage_volume:')) {
            $volumeId = (int) substr($disk, 15);
            $volume = StorageVolume::find($volumeId);
            if ($volume) {
                if ($volume->driver === 'local') @unlink($this->localVolumePath($volume, $path));
                else $this->s3->delete($volume, $path);
            }
        } else {
            Storage::disk($disk ?: 'private')->delete($path);
        }

        $managed = StorageManagedFile::query()
            ->when($volumeId, fn ($q) => $q->where('storage_volume_id', $volumeId), fn ($q) => $q->whereNull('storage_volume_id'))
            ->where('file_key', $path)
            ->whereNotIn('status', ['deleted'])
            ->first();
        if ($managed) {
            if ($volumeId && $managed->volume) $managed->volume->decrement('used_bytes', (int) $managed->size_bytes);
                $managed->volume->decrement('object_count');
            $managed->update(['status' => 'deleted']);
        }
    }

    public function downloadTo(string $disk, string $path, string $target): void
    {
        if (str_starts_with($disk, 'storage_volume:')) {
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
        $this->writeToVolume($volume, $key, $localPath);
    }

    public function volumeDisk(StorageVolume $volume): string
    {
        return 'storage_volume:'.$volume->id;
    }

    private function selectVolume(string $drive, int $bytes): ?StorageVolume
    {
        return StorageVolume::query()
            ->where('drive_key', $drive)
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [$bytes]))
            ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', $bytes))
            ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('(SELECT COUNT(*) FROM storage_managed_files WHERE storage_managed_files.storage_volume_id = storage_volumes.id AND storage_managed_files.status != ?) < storage_volumes.file_limit', ['deleted']))
            ->orderBy('priority')
            ->first();
    }

    private function localVolumePath(StorageVolume $volume, string $key): string
    {
        $root = storage_path('app/storage-volumes/'.$volume->id);
        $path = $root.'/'.ltrim($key, '/');
        abort_unless(str_starts_with($path, $root.'/'), 400);
        return $path;
    }

    private function writeToVolume(StorageVolume $volume, string $key, string $localPath): void
    {
        if ($volume->driver === 'local') {
            $target = $this->localVolumePath($volume, $key);
            if (! is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
            if (! copy($localPath, $target)) throw new RuntimeException('کپی فایل روی فضای محلی هاست ناموفق بود.');
            return;
        }
        if ($volume->driver !== 's3') throw new RuntimeException('آداپتور این نوع سرویس هنوز نصب نشده است.');
        $this->s3->putFile($volume, $key, $localPath);
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
        $disk = $volume ? $this->volumeDisk($volume) : 'private';
        $record = StorageManagedFile::create([
            'storage_volume_id' => $volume?->id,
            'drive_key' => $classification === self::CRITICAL ? 'iran_primary' : 'foreign_primary',
            'file_key' => $key,
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
            'metadata' => array_merge($metadata, ['disk' => $disk, 'original_extension' => $file->getClientOriginalExtension()]),
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
    }
}