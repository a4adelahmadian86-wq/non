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
            $mime = $config->get('mimetype');
            if (! is_string($mime) || $mime === '') {
                $sample = fread($temporary, 8192) ?: '';
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample) ?: 'application/octet-stream';
                rewind($temporary);
            }
            $this->persist($path, $temporary, $size, $checksum, $config, $mime);
        } finally {
            fclose($temporary);
        }
    }

    private function persist(string $logicalPath, $stream, int $size, string $checksum, Config $config, string $mime): void
    {
        $existing = StorageManagedFile::query()->where('logical_path', $logicalPath)->first();
        $classification = $this->classificationFor($logicalPath, $config);
        $drive = $this->driveFor($classification);
        $volume = $this->reserveVolume($drive, $size);
        $objectKey = 'objects/'.now()->format('Y/m').'/'.Str::uuid().'-'.basename($logicalPath);
        $disk = $this->connections->disk($volume);
        $committed = false;

        try {
            $disk->writeStream($objectKey, $stream, [
                'visibility' => 'private',
                'mimetype' => $mime,
            ]);

            $actualSize = (int) $disk->size($objectKey);
            if ($actualSize !== $size) {
                throw new RuntimeException('Stored object size verification failed.');
            }

            $oldVolumeId = $existing?->storage_volume_id;
            $oldKey = $existing?->file_key;
            $oldSize = (int) ($existing?->size_bytes ?? 0);
            $oldReplicaId = $existing?->replicated_to_volume_id;
            $oldReplicaKey = $existing?->replica_file_key;
            $replicationRequired = $this->replicationRequired($classification);
            DB::transaction(function () use ($existing, $volume, $logicalPath, $objectKey, $size, $checksum, $classification, $config, $oldVolumeId, $oldSize, $replicationRequired, $mime) {
                if ($existing) {
                    $existing->update([
                        'storage_volume_id' => $volume->id,
                        'drive_key' => $volume->drive_key,
                        'file_key' => $objectKey,
                        'original_name' => (string) ($config->get('farast_original_name') ?: ($existing->original_name ?: basename($logicalPath))),
                        'mime' => $mime,
                        'size_bytes' => $size,
                        'checksum' => $checksum,
                        'classification' => $classification,
                        'source_module' => $this->sourceFor($logicalPath),
                        'source_type' => $config->get('farast_source_type') ?? $existing->source_type,
                        'source_id' => $config->get('farast_source_id') ?? $existing->source_id,
                        'product_id' => $config->get('farast_product_id') ?? $existing->product_id,
                        'owner_user_id' => $config->get('farast_owner_user_id') ?? $existing->owner_user_id,
                        'replicated_to_volume_id' => null,
                        'replica_file_key' => null,
                        'status' => 'active',
                        'visibility' => 'private',
                        'metadata' => ['fallback' => (bool) data_get($volume->metadata, 'local_fallback', false), 'replication_status' => $replicationRequired ? 'queued' : 'not_required'],
                    ]);
                    if ($oldVolumeId) {
                        StorageVolume::whereKey($oldVolumeId)->decrement('used_bytes', $oldSize);
                    }
                } else {
                    StorageManagedFile::create([
                        'storage_volume_id' => $volume->id,
                        'drive_key' => $volume->drive_key,
                        'logical_path' => $logicalPath,
                        'file_key' => $objectKey,
                        'original_name' => (string) ($config->get('farast_original_name') ?: basename($logicalPath)),
                        'mime' => $mime,
                        'size_bytes' => $size,
                        'checksum' => $checksum,
                        'classification' => $classification,
                        'source_module' => $this->sourceFor($logicalPath),
                        'source_type' => $config->get('farast_source_type'),
                        'source_id' => $config->get('farast_source_id'),
                        'product_id' => $config->get('farast_product_id'),
                        'owner_user_id' => $config->get('farast_owner_user_id'),
                        'replicated_to_volume_id' => null,
                        'replica_file_key' => null,
                        'visibility' => 'private',
                        'status' => 'active',
                        'metadata' => ['fallback' => (bool) data_get($volume->metadata, 'local_fallback', false), 'replication_status' => $replicationRequired ? 'queued' : 'not_required'],
                    ]);
                }
                $volume->increment('used_bytes', $size);
                $volume->decrement('reserved_bytes', $size);
                $volume->decrement('reserved_files');
            });
            $committed = true;

            if ($oldVolumeId && $oldKey) {
                try {
                    $old = StorageVolume::find($oldVolumeId);
                    if ($old) {
                        $this->connections->disk($old)->delete($oldKey);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            if ($oldReplicaId && $oldReplicaKey) {
                try {
                    $oldReplica = StorageVolume::find($oldReplicaId);
                    if ($oldReplica) {
                        $this->connections->disk($oldReplica)->delete($oldReplicaKey);
                        $oldReplica->decrement('used_bytes', $oldSize);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            if ($replicationRequired) {
                $managedRecord = StorageManagedFile::query()->where('logical_path', $logicalPath)->first();
                if ($managedRecord) {
                    \App\Jobs\ReplicateStorageFile::dispatch($managedRecord->id)->afterCommit();
                }
            }
        } catch (Throwable $exception) {
            if ($committed) {
                // The logical record and new object are already committed. A queue or
                // cleanup failure must not turn a successful upload into a broken record.
                report($exception);
                return;
            }
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
                ->withCount(['files' => fn ($query) => $query->where('status', 'active'), 'replicas' => fn ($query) => $query->where('status', 'active'), 'backups' => fn ($query) => $query->where('status', 'completed')])
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
                if ($volume->file_limit !== null && ($volume->files_count + $volume->replicas_count + $volume->backups_count + $volume->reserved_files + 1) > $volume->file_limit) {
                    continue;
                }
                $volume->increment('reserved_bytes', $size);
                $volume->increment('reserved_files');
                return $volume;
            }

            if (! config('storage.fallback.enabled', true)) {
                throw new RuntimeException('No healthy storage volume is available for this storage class.');
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

    public function rebalanceFallbackFiles(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $moved = 0;
        $records = StorageManagedFile::query()
            ->with('volume')
            ->where('status', 'active')
            ->whereNotNull('logical_path')
            ->whereHas('volume', fn ($query) => $query->whereJsonContains('metadata->local_fallback', true))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($records as $record) {
            if (! $record->volume || ! $this->hasDedicatedVolume($record->drive_key)) {
                continue;
            }
            $oldVolumeId = $record->storage_volume_id;
            $stream = $this->readStream($record->logical_path);
            if (! is_resource($stream)) {
                continue;
            }
            $config = new Config([
                'farast_classification' => $record->classification,
                'farast_source_type' => $record->source_type,
                'farast_source_id' => $record->source_id,
                'farast_product_id' => $record->product_id,
                'farast_owner_user_id' => $record->owner_user_id,
                'farast_original_name' => $record->original_name,
                'mimetype' => $record->mime,
            ]);
            try {
                $this->writeStream($record->logical_path, $stream, $config);
                $record->refresh();
                if ($record->storage_volume_id !== $oldVolumeId) {
                    $moved++;
                }
            } catch (Throwable $exception) {
                report($exception);
            } finally {
                fclose($stream);
            }
        }

        return $moved;
    }

    private function hasDedicatedVolume(string $drive): bool
    {
        return StorageVolume::query()
            ->where('drive_key', $drive)
            ->where('driver', '!=', 'local')
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->exists();
    }

    private function replicationRequired(string $classification): bool
    {
        $requiredCopies = $classification === 'critical'
            ? (int) config('storage.replication.critical_copies', 2)
            : (int) config('storage.replication.non_critical_copies', 1);

        return $requiredCopies > 1;
    }

    public function replicate(int $fileId): bool
    {
        $record = StorageManagedFile::query()->with(['volume', 'replicaVolume'])->findOrFail($fileId);
        if ($record->status !== 'active' || ! $record->volume) {
            return false;
        }

        if ($record->replicated_to_volume_id && $record->replicaVolume?->status === 'healthy' && $record->replica_file_key) {
            try {
                $replicaDisk = $this->connections->disk($record->replicaVolume);
                if ($replicaDisk->exists($record->replica_file_key)
                    && (int) $replicaDisk->size($record->replica_file_key) === $record->size_bytes) {
                    return true;
                }
                $record->replicaVolume->decrement('used_bytes', $record->size_bytes);
                $record->update(['replicated_to_volume_id' => null, 'replica_file_key' => null]);
            } catch (Throwable $exception) {
                report($exception);
                $record->replicaVolume->update(['status' => 'error', 'last_checked_at' => now()]);
            }
        }

        $oldReplicaId = $record->replicated_to_volume_id;
        $oldReplicaKey = $record->replica_file_key;
        $target = $this->reserveReplicaVolume($record);
        if (! $target) {
            $metadata = (array) ($record->metadata ?? []);
            $metadata['replication_status'] = 'waiting_for_replica';
            $record->update(['metadata' => $metadata]);
            return false;
        }

        $replicaKey = 'replicas/'.$record->id.'/'.Str::uuid().'-'.basename($record->file_key);
        $reserved = true;
        try {
            $sourceDisk = $this->connections->disk($record->volume);
            $sourceStream = $sourceDisk->readStream($record->file_key);
            if (! is_resource($sourceStream)) {
                throw new RuntimeException('Primary storage object cannot be opened for replication.');
            }

            $temporary = tmpfile();
            if (! is_resource($temporary)) {
                fclose($sourceStream);
                throw new RuntimeException('Unable to allocate replication stream.');
            }
            $hash = hash_init('sha256');
            $size = 0;
            try {
                while (! feof($sourceStream)) {
                    $chunk = fread($sourceStream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read primary object during replication.');
                    }
                    if ($chunk === '') continue;
                    $size += strlen($chunk);
                    hash_update($hash, $chunk);
                    fwrite($temporary, $chunk);
                }
            } finally {
                fclose($sourceStream);
            }

            $checksum = hash_final($hash);
            if ($size !== $record->size_bytes || ! hash_equals((string) $record->checksum, $checksum)) {
                fclose($temporary);
                throw new RuntimeException('Primary object checksum mismatch during replication.');
            }
            rewind($temporary);

            $targetDisk = $this->connections->disk($target);
            $targetDisk->writeStream($replicaKey, $temporary, ['visibility' => 'private', 'mimetype' => $record->mime]);
            fclose($temporary);

            if ((int) $targetDisk->size($replicaKey) !== $size) {
                throw new RuntimeException('Replica object size verification failed.');
            }
            $replicaStream = $targetDisk->readStream($replicaKey);
            if (! is_resource($replicaStream)) {
                throw new RuntimeException('Replica object cannot be read back for verification.');
            }
            $replicaHash = hash_init('sha256');
            try {
                hash_update_stream($replicaHash, $replicaStream);
            } finally {
                fclose($replicaStream);
            }
            if (! hash_equals($checksum, hash_final($replicaHash))) {
                throw new RuntimeException('Replica object checksum verification failed.');
            }

            DB::transaction(function () use ($record, $target, $replicaKey, $size) {
                $locked = StorageManagedFile::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'active' || $locked->storage_volume_id !== $record->storage_volume_id
                    || ! hash_equals((string) $locked->checksum, (string) $record->checksum)) {
                    throw new RuntimeException('Managed file changed while its replica was being written.');
                }
                $locked->update([
                    'replicated_to_volume_id' => $target->id,
                    'replica_file_key' => $replicaKey,
                    'metadata' => array_merge((array) ($locked->metadata ?? []), [
                        'replication_status' => 'ready',
                        'replication_verified_at' => now()->toIso8601String(),
                    ]),
                ]);
                $target->increment('used_bytes', $size);
                $target->decrement('reserved_bytes', $size);
                $target->decrement('reserved_files');
            });
            $reserved = false;

            if ($oldReplicaId && $oldReplicaKey && $oldReplicaKey !== $replicaKey) {
                try {
                    $oldReplica = StorageVolume::find($oldReplicaId);
                    if ($oldReplica) {
                        $this->connections->disk($oldReplica)->delete($oldReplicaKey);
                        $oldReplica->decrement('used_bytes', $record->size_bytes);
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            return true;
        } catch (Throwable $exception) {
            if ($reserved) {
                $target->decrement('reserved_bytes', $record->size_bytes);
                $target->decrement('reserved_files');
            }
            try {
                $this->connections->disk($target)->delete($replicaKey);
            } catch (Throwable) {
                // Keep the original replication failure.
            }
            $fresh = $record->fresh();
            if ($fresh) {
                $metadata = (array) ($fresh->metadata ?? []);
                $metadata['replication_status'] = 'failed';
                $metadata['replication_error'] = mb_substr($exception->getMessage(), 0, 500);
                $fresh->update(['metadata' => $metadata]);
            }
            throw $exception;
        }
    }

    private function reserveReplicaVolume(StorageManagedFile $record): ?StorageVolume
    {
        $primary = $record->volume;
        if (! $primary) return null;

        return DB::transaction(function () use ($record, $primary) {
            $query = StorageVolume::query()
                ->where('drive_key', $record->drive_key)
                ->where('id', '!=', $primary->id)
                ->where('is_active', true)
                ->where('status', 'healthy')
                ->withCount(['files' => fn ($query) => $query->where('status', 'active'), 'replicas' => fn ($query) => $query->where('status', 'active'), 'backups' => fn ($query) => $query->where('status', 'completed')])
                ->orderBy('priority')
                ->orderBy('used_bytes');

            if ($primary->provider) {
                $query->where(function ($builder) use ($primary) {
                    $builder->whereNull('provider')->orWhere('provider', '!=', $primary->provider);
                });
            }

            foreach ($query->lockForUpdate()->get() as $volume) {
                if ($volume->max_file_bytes !== null && $record->size_bytes > $volume->max_file_bytes) continue;
                if ($volume->capacity_bytes !== null && ($volume->used_bytes + $volume->reserved_bytes + $record->size_bytes) > $volume->capacity_bytes) continue;
                if ($volume->file_limit !== null && ($volume->files_count + $volume->replicas_count + $volume->backups_count + $volume->reserved_files + 1) > $volume->file_limit) continue;
                $volume->increment('reserved_bytes', $record->size_bytes);
                $volume->increment('reserved_files');
                return $volume;
            }

            return null;
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
        $path = str_replace('\\\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true) || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid managed storage path.');
        }
        return $path;
    }

    private function record(string $path): ?StorageManagedFile
    {
        return StorageManagedFile::query()->with('volume')->where('logical_path', $this->normalize($path))->where('status', 'active')->first();
    }

    public function exists(string $path, ?string $legacyPath = null): bool
    {
        if ($legacyPath !== null) {
            return $path === 'managed' ? $this->exists($legacyPath) : \Illuminate\Support\Facades\Storage::disk($path)->exists($legacyPath);
        }
        $record = $this->record($path);
        if (! $record) {
            return \Illuminate\Support\Facades\Storage::disk('private')->exists($path);
        }
        try {
            if ($this->connections->disk($record->volume)->exists($record->file_key)) {
                return true;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($record->replicaVolume && $record->replica_file_key) {
            try {
                return $this->connections->disk($record->replicaVolume)->exists($record->replica_file_key);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        return false;
    }

    public function get(string $path, ?string $legacyPath = null): string
    {
        if ($legacyPath !== null) {
            return $path === 'managed' ? $this->read($legacyPath) : (string) \Illuminate\Support\Facades\Storage::disk($path)->get($legacyPath);
        }
        return $this->read($path);
    }

    public function read(string $path): string
    {
        $record = $this->record($path);
        if (! $record) {
            return (string) \Illuminate\Support\Facades\Storage::disk('private')->get($path);
        }
        try {
            return (string) $this->connections->disk($record->volume)->get($record->file_key);
        } catch (Throwable $primaryException) {
            if ($record->replicaVolume && $record->replica_file_key) {
                report($primaryException);
                return (string) $this->connections->disk($record->replicaVolume)->get($record->replica_file_key);
            }
            throw $primaryException;
        }
    }

    public function readStream(string $path, ?string $legacyPath = null)
    {
        if ($legacyPath !== null) {
            return $path === 'managed' ? $this->readStream($legacyPath) : \Illuminate\Support\Facades\Storage::disk($path)->readStream($legacyPath);
        }
        $record = $this->record($path);
        if (! $record) {
            return \Illuminate\Support\Facades\Storage::disk('private')->readStream($path);
        }
        try {
            return $this->connections->disk($record->volume)->readStream($record->file_key);
        } catch (Throwable $primaryException) {
            if ($record->replicaVolume && $record->replica_file_key) {
                report($primaryException);
                return $this->connections->disk($record->replicaVolume)->readStream($record->replica_file_key);
            }
            throw $primaryException;
        }
    }

    public function delete(string $path, ?string $legacyPath = null): void
    {
        if ($legacyPath !== null) {
            if ($path === 'managed') {
                $this->delete($legacyPath);
            } else {
                \Illuminate\Support\Facades\Storage::disk($path)->delete($legacyPath);
            }
            return;
        }
        $record = $this->record($path);
        if (! $record) {
            \Illuminate\Support\Facades\Storage::disk('private')->delete($path);
            return;
        }
        $primaryDeleted = false;
        $replicaDeleted = false;
        $failures = [];

        try {
            $this->connections->disk($record->volume)->delete($record->file_key);
            $primaryDeleted = true;
        } catch (Throwable $exception) {
            $failures[] = $exception;
        }

        if ($record->replicaVolume && $record->replica_file_key) {
            try {
                $this->connections->disk($record->replicaVolume)->delete($record->replica_file_key);
                $replicaDeleted = true;
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if (! $primaryDeleted && ! $replicaDeleted) {
            throw $failures[0] ?? new RuntimeException('Managed object could not be deleted from any replica.');
        }

        DB::transaction(function () use ($record, $primaryDeleted, $replicaDeleted) {
            $metadata = (array) ($record->metadata ?? []);
            if (! $primaryDeleted || ($record->replicated_to_volume_id && ! $replicaDeleted)) {
                $metadata['orphan_cleanup_pending'] = true;
            }
            $record->update(['status' => 'deleted', 'metadata' => $metadata]);
            if ($primaryDeleted) {
                StorageVolume::whereKey($record->storage_volume_id)->decrement('used_bytes', $record->size_bytes);
            }
            if ($replicaDeleted && $record->replicated_to_volume_id) {
                StorageVolume::whereKey($record->replicated_to_volume_id)->decrement('used_bytes', $record->size_bytes);
            }
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
        if (trim($path) === '') {
            return StorageManagedFile::query()->where('status', 'active')->exists();
        }
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
        $stream = $this->readStream($source);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open source stream for copy.');
        }
        try {
            $this->writeStream($destination, $stream, new Config());
        } finally {
            fclose($stream);
        }
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }
}