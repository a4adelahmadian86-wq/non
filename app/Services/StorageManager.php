<?php

namespace App\Services;

use App\Models\StorageManagedFile;
use App\Models\StorageVolume;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use RuntimeException;
use Throwable;

class StorageManager
{
    private const DRIVES = ['iran_primary', 'iran_backup', 'foreign_primary', 'foreign_backup'];

    public function __construct(private readonly StorageConnectionService $connections)
    {
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $stream, Config $config): void
    {
        $path = $this->normalize($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Invalid storage stream.');
        }

        $temporary = tmpfile();
        if (! is_resource($temporary)) {
            throw new RuntimeException('Unable to create a temporary upload stream.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read upload stream.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
                fwrite($temporary, $chunk);
            }
            if ($size < 1) {
                throw new RuntimeException('Empty files cannot be stored.');
            }
            $checksum = hash_final($hash);
            rewind($temporary);
            $this->persist($path, $temporary, $size, $checksum, $config);
        } finally {
            fclose($temporary);
        }
    }

    private function persist(string $logicalPath, $stream, int $size, string $checksum, Config $config): void
    {
        $existing = StorageManagedFile::query()->where('logical_path', $logicalPath)->first();
        $classification = $this->classificationFor($logicalPath, $config);
        $drive = $this->driveFor($classification);
        $volume = $this->reserveVolume($drive, $size);
        $objectKey = 'objects/'.now()->format('Y/m').'/'.Str::uuid().'-'.basename($logicalPath);
        $disk = $this->connections->disk($volume);

        try {
            $disk->writeStream($objectKey, $stream, [
                'visibility' => 'private',
                'mimetype' => $config->get('mimetype'),
            ]);

            $actualSize = (int) $disk->size($objectKey);
            if ($actualSize !== $size) {
                throw new RuntimeException('Stored object size verification failed.');
            }

            $oldVolumeId = $existing?->storage_volume_id;
            $oldKey = $existing?->file_key;
            DB::transaction(function () use ($existing, $volume, $logicalPath, $objectKey, $size, $checksum, $classification, $config, $oldVolumeId) {
                if ($existing) {
                    $existing->update([
                        'storage_volume_id' => $volume->id,
                        'drive_key' => $volume->drive_key,
                        'file_key' => $objectKey,
                        'size_bytes' => $size,
                        'checksum' => $checksum,
                        'classification' => $classification,
                        'source_module' => $this->sourceFor($logicalPath),
                        'status' => 'active',
                        'visibility' => 'private',
                        'metadata' => ['fallback' => (bool) data_get($volume->metadata, 'local_fallback', false)],
                    ]);
                    if ($oldVolumeId && $oldVolumeId !== $volume->id) {
                        StorageVolume::whereKey($oldVolumeId)->decrement('used_bytes', (int) $existing->getOriginal('size_bytes'));
                    }
                } else {
                    StorageManagedFile::create([
                        'storage_volume_id' => $volume->id,
                        'drive_key' => $volume->drive_key,
                        'logical_path' => $logicalPath,
                        'file_key' => $objectKey,
                        'original_name' => basename($logicalPath),
                        'mime' => $config->get('mimetype'),
                        'size_bytes' => $size,
                        'checksum' => $checksum,
                        'classification' => $classification,
                        'source_module' => $this->sourceFor($logicalPath),
                        'visibility' => 'private',
                        'status' => 'active',
                        'metadata' => ['fallback' => (bool) data_get($volume->metadata, 'local_fallback', false)],
                    ]);
                }
                $volume->increment('used_bytes', $size);
                $volume->decrement('reserved_bytes', $size);
                $volume->decrement('reserved_files');
            });

            if ($oldVolumeId && $oldKey && $oldVolumeId !== $volume->id) {
                try {
                    $old = StorageVolume::find($oldVolumeId);
                    if ($old) {
                        $this->connections->disk($old)->delete($oldKey);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        } catch (Throwable $exception) {
            $volume->decrement('reserved_bytes', $size);
            $volume->decrement('reserved_files');
            try {
                $disk->delete($objectKey);
            } catch (Throwable) {
                // Preserve the original storage failure.
            }
            throw $exception;
        }
    }

    private function reserveVolume(string $drive, int $size): StorageVolume
    {
        return DB::transaction(function () use ($drive, $size) {
            $candidates = StorageVolume::query()
                ->where('drive_key', $drive)
                ->where('is_active', true)
                ->where('status', 'healthy')
                ->orderBy('priority')
                ->withCount(['files' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('used_bytes')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $volume) {
                if ($volume->max_file_bytes !== null && $size > $volume->max_file_bytes) {
                    continue;
                }
                if ($volume->capacity_bytes !== null && ($volume->used_bytes + $volume->reserved_bytes + $size) > $volume->capacity_bytes) {
                    continue;
                }
                if ($volume->file_limit !== null && ($volume->files_count + $volume->reserved_files + 1) > $volume->file_limit) {
                    continue;
                }
                $volume->increment('reserved_bytes', $size);
                $volume->increment('reserved_files');
                return $volume;
            }

            $fallback = StorageVolume::query()
                ->where('drive_key', $drive)
                ->where('driver', 'local')
                ->where('status', 'healthy')
                ->where('is_active', true)
                ->whereJsonContains('metadata->local_fallback', true)
                ->lockForUpdate()
                ->first();

            if (! $fallback) {
                $fallback = StorageVolume::create([
                    'drive_key' => $drive,
                    'name' => 'ذخیره‌سازی محلی پشتیبان',
                    'provider' => 'میزبان فعلی برنامه',
                    'driver' => 'local',
                    'region' => env('FARAST_LOCAL_REGION', 'نامشخص'),
                    'capacity_bytes' => null,
                    'used_bytes' => 0,
                    'reserved_bytes' => 0,
                    'reserved_files' => 0,
                    'priority' => 10000,
                    'is_active' => true,
                    'status' => 'healthy',
                    'metadata' => ['local_fallback' => true, 'notice' => 'Fallback only; configure a dedicated volume for this drive.'],
                    'capabilities' => ['read' => true, 'write' => true, 'delete' => true],
                ]);
            }
            if ($fallback->max_file_bytes !== null && $size > $fallback->max_file_bytes) {
                throw new RuntimeException('No storage volume has enough capacity for this file.');
            }
            $fallback->increment('reserved_bytes', $size);
            $fallback->increment('reserved_files');

            return $fallback;
        });
    }

    private function classificationFor(string $path, Config $config): string
    {
        $explicit = $config->get('farast_classification');
        if (in_array($explicit, ['critical', 'non_critical', 'backup'], true)) {
            return $explicit;
        }
        $normalized = strtolower($path);
        foreach ((array) config('storage.routing.non_critical_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, trim(strtolower($prefix), '/').'/')) {
                return 'non_critical';
            }
        }
        if (str_starts_with($normalized, 'backups/')) {
            return 'backup';
        }
        return 'critical';
    }

    private function driveFor(string $classification): string
    {
        return match ($classification) {
            'non_critical' => 'foreign_primary',
            'backup' => 'foreign_backup',
            default => 'iran_primary',
        };
    }

    private function sourceFor(string $path): string
    {
        return explode('/', $path, 2)[0] ?: 'application';
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '../') || $path === '..' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid managed storage path.');
        }
        return $path;
    }

    private function record(string $path): ?StorageManagedFile
    {
        return StorageManagedFile::query()->with('volume')->where('logical_path', $this->normalize($path))->where('status', 'active')->first();
    }

    public function exists(string $path): bool
    {
        $record = $this->record($path);
        if (! $record) {
            return \Illuminate\Support\Facades\Storage::disk('private')->exists($path);
        }
        try {
            return $this->connections->disk($record->volume)->exists($record->file_key);
        } catch (Throwable $exception) {
            report($exception);
            return false;
        }
    }

    public function read(string $path): string
    {
        $record = $this->record($path);
        if (! $record) {
            return (string) \Illuminate\Support\Facades\Storage::disk('private')->get($path);
        }
        return (string) $this->connections->disk($record->volume)->get($record->file_key);
    }

    public function readStream(string $path)
    {
        $record = $this->record($path);
        if (! $record) {
            return \Illuminate\Support\Facades\Storage::disk('private')->readStream($path);
        }
        return $this->connections->disk($record->volume)->readStream($record->file_key);
    }

    public function delete(string $path): void
    {
        $record = $this->record($path);
        if (! $record) {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($path);
            return;
        }
        $this->connections->disk($record->volume)->delete($record->file_key);
        DB::transaction(function () use ($record) {
            $record->update(['status' => 'deleted']);
            StorageVolume::whereKey($record->storage_volume_id)->decrement('used_bytes', $record->size_bytes);
        });
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($this->normalize($path), '/').'/';
        StorageManagedFile::query()->where('logical_path', 'like', addcslashes($prefix, '%_').'%')->where('status', 'active')->orderBy('id')->chunkById(100, function ($records) {
            foreach ($records as $record) {
                $this->delete($record->logical_path);
            }
        });
        \Illuminate\Support\Facades\Storage::disk('private')->deleteDirectory($path);
    }

    public function createDirectory(string $path): void
    {
        // Logical directories are created implicitly by their first object.
    }

    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($this->normalize($path), '/').'/';
        return StorageManagedFile::query()->where('logical_path', 'like', addcslashes($prefix, '%_').'%')->where('status', 'active')->exists()
            || \Illuminate\Support\Facades\Storage::disk('private')->directoryExists($path);
    }

    public function attributes(string $path): FileAttributes
    {
        $record = $this->record($path);
        if (! $record) {
            $disk = \Illuminate\Support\Facades\Storage::disk('private');
            return new FileAttributes($path, $disk->exists($path) ? (int) $disk->size($path) : null, 'private', null, $disk->exists($path) ? $disk->mimeType($path) : null);
        }
        return new FileAttributes($path, $record->size_bytes, 'private', $record->updated_at?->timestamp, $record->mime);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($this->normalize($path), '/').'/';
        $query = StorageManagedFile::query()->where('status', 'active')->where('logical_path', 'like', addcslashes($prefix, '%_').'%')->orderBy('logical_path');
        foreach ($query->cursor() as $record) {
            $relative = substr($record->logical_path, strlen($prefix));
            if (! $deep && str_contains($relative, '/')) {
                $directory = $prefix.explode('/', $relative, 2)[0];
                yield new DirectoryAttributes($directory);
            } else {
                yield new FileAttributes($record->logical_path, $record->size_bytes, 'private', $record->updated_at?->timestamp, $record->mime);
            }
        }
    }

    public function copy(string $source, string $destination): void
    {
        $this->write($destination, $this->read($source), new Config());
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }
}