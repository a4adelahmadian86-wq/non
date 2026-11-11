<?php

namespace App\Services\Storage;

use App\Models\StorageManagedFile;
use App\Models\FarastStorageObject;
use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Models\UserFile;
use App\Models\StoreProductFile;
use App\Models\ConversationMessage;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StorageTransferService
{
    public function __construct(private readonly S3CompatibleAdapter $s3) {}

    public function syncSourceReference(StorageManagedFile $file, string $disk): void
    {
        if (! $file->source_id || ! $file->source_type) return;
        $reference = match ($file->source_type) {
            UserFile::class => UserFile::find($file->source_id),
            StoreProductFile::class => StoreProductFile::find($file->source_id),
            ConversationMessage::class => ConversationMessage::find($file->source_id),
            TicketMessage::class => TicketMessage::find($file->source_id),
            FarastStorageObject::class => FarastStorageObject::find($file->source_id),
            default => null,
        };
        if (! $reference) return;

        if ($reference instanceof UserFile) {
            $isRemote = str_starts_with($disk, 'storage_volume:');
            $reference->update([
                'disk' => $disk, 'path' => $file->file_key,
                'status' => $isRemote ? 'remote' : 'pending_transfer',
                'remote_path' => $isRemote ? $file->file_key : null,
                'local_expires_at' => null,
                'remote_expires_at' => null,
                'transferred_at' => $isRemote ? now() : null,
            ]);
            return;
        }
        if ($reference instanceof StoreProductFile) {
            $reference->update(['disk' => $disk, 'path' => $file->file_key]);
            return;
        }

        if ($reference instanceof FarastStorageObject) {
            $reference->update(['disk' => $disk, 'path' => $file->file_key]);
            return;
        }

        $attachments = (array) ($reference->attachments ?? []);
        $changed = false;
        foreach ($attachments as &$attachment) {
            if ((int) ($attachment['managed_file_id'] ?? 0) === (int) $file->id) {
                $attachment['disk'] = $disk;
                $attachment['path'] = $file->file_key;
                $changed = true;
            }
        }
        unset($attachment);
        if ($changed) $reference->update(['attachments' => $attachments]);
    }

    public function rebindToStaging(StorageManagedFile $file, string $stagingPath): void
    {
        DB::transaction(function () use ($file, $stagingPath) {
            $stagingPath = StoragePath::normalizeObjectKey($stagingPath);
            $sourceVolumeId = $file->storage_volume_id;
            if ($sourceVolumeId !== null) {
                $sourceVolume = StorageVolume::query()->lockForUpdate()->find($sourceVolumeId);
                if ($sourceVolume) {
                    DB::table('storage_volumes')->where('id', $sourceVolumeId)->update([
                        'used_bytes' => DB::raw('CASE WHEN used_bytes >= '.(int) $file->size_bytes.' THEN used_bytes - '.(int) $file->size_bytes.' ELSE 0 END'),
                        'object_count' => DB::raw('CASE WHEN object_count > 0 THEN object_count - 1 ELSE 0 END'),
                    ]);
                }
            }
            $metadata = (array) $file->metadata;
            $metadata['disk'] = 'private';
            $metadata['staging_disk'] = 'private';
            $metadata['target_drive'] = $file->drive_key;
            $metadata['reference_pending'] = false;
            $metadata['restored_at'] = now()->toIso8601String();
            $file->update([
                'storage_volume_id' => null,
                'file_key' => $stagingPath,
                'status' => 'pending_transfer',
                'metadata' => $metadata,
            ]);
            $this->syncSourceReference($file->fresh(), 'private');
        });
    }

    public function transferPending(int $limit = 100): int
    {
        if (StorageBackupRun::query()->where('source_name', 'snapshot_lock')->where('status', 'running')->where('started_at', '>=', now()->subHours(8))->exists()) {
            return 0;
        }

        // Recover abandoned claims. A transfer request has a bounded HTTP timeout,
        // so a four-hour lease is deliberately longer than any valid single attempt.
        StorageManagedFile::query()
            ->where('status', 'transferring')
            ->where('updated_at', '<', now()->subHours(4))
            ->update(['status' => 'transfer_error']);

        // Claim rows in a short transaction. Concurrent scheduler/worker invocations
        // cannot select the same staged file after the claim commits.
        $files = DB::transaction(function () use ($limit) {
            $candidates = StorageManagedFile::query()
                ->whereNull('storage_volume_id')
                ->whereIn('status', ['pending_transfer', 'transfer_error'])
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(max(1, $limit))
                ->get();

            $claimed = collect();
            foreach ($candidates as $file) {
                if (data_get($file->metadata, 'reference_pending', false)) {
                    continue;
                }

                $metadata = (array) $file->metadata;
                $metadata['transfer_started_at'] = now()->toIso8601String();
                $metadata['transfer_attempts'] = (int) ($metadata['transfer_attempts'] ?? 0) + 1;
                $file->update(['status' => 'transferring', 'metadata' => $metadata]);
                $claimed->push($file->fresh());
            }

            return $claimed;
        });

        $moved = 0;
        foreach ($files as $file) {
            $targetDrive = (string) data_get($file->metadata, 'target_drive', $file->drive_key);
            $target = StorageVolume::query()
                ->where('drive_key', $targetDrive)
                ->where('is_active', true)
                ->where('status', 'healthy')
                ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [(int) $file->size_bytes]))
                ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', (int) $file->size_bytes))
                ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('object_count < file_limit'))
                ->orderBy('priority')
                ->first();

            if (! $target) {
                $file->update(['status' => 'pending_transfer']);
                continue;
            }

            $sourceDisk = (string) data_get($file->metadata, 'staging_disk', 'private');
            $source = Storage::disk($sourceDisk);
            if (! $source->exists($file->file_key)) {
                $file->update([
                    'status' => 'transfer_error',
                    'metadata' => array_merge((array) $file->metadata, ['transfer_error' => 'staging_file_missing']),
                ]);
                continue;
            }

            $temp = tempnam(sys_get_temp_dir(), 'farast-transfer');
            if (! is_string($temp)) {
                $file->update(['status' => 'transfer_error']);
                continue;
            }

            $uploaded = false;
            try {
                $stream = $source->readStream($file->file_key);
                if (! is_resource($stream)) {
                    throw new RuntimeException('خواندن جریان فایل موقت ناموفق بود.');
                }
                $out = fopen($temp, 'wb');
                if (! is_resource($out)) {
                    fclose($stream);
                    throw new RuntimeException('ساخت فایل موقت انتقال ناموفق بود.');
                }
                try {
                    if (stream_copy_to_stream($stream, $out) === false) {
                        throw new RuntimeException('انتقال جریان فایل موقت ناموفق بود.');
                    }
                } finally {
                    fclose($out);
                    fclose($stream);
                }

                $sourceHash = hash_file('sha256', $temp);
                if (! is_string($sourceHash) || ! hash_equals((string) $file->checksum, $sourceHash)) {
                    throw new RuntimeException('Checksum فایل مبدأ با رکورد ذخیره‌سازی مطابقت ندارد.');
                }

                $objectKey = StoragePath::normalizeObjectKey($file->file_key);
                if ($target->driver === 's3') {
                    $this->s3->putFile($target, $objectKey, $temp);
                    $uploaded = true;
                    $verificationPath = tempnam(sys_get_temp_dir(), 'farast-transfer-verify');
                    if (! is_string($verificationPath)) {
                        throw new RuntimeException('ساخت فایل موقت برای تأیید انتقال ناموفق بود.');
                    }
                    try {
                        $this->s3->downloadTo($target, $objectKey, $verificationPath);
                        $destinationHash = hash_file('sha256', $verificationPath);
                    } finally {
                        @unlink($verificationPath);
                    }
                } elseif ($target->driver === 'local') {
                    $root = storage_path('app/storage-volumes/'.$target->id);
                    $destination = $root.'/'.$objectKey;
                    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0750, true) && ! is_dir(dirname($destination))) {
                        throw new RuntimeException('ساخت پوشه مقصد ناموفق بود.');
                    }
                    $destinationTemp = dirname($destination).'/.farast-transfer-'.bin2hex(random_bytes(12)).'.tmp';
                    try {
                        if (! copy($temp, $destinationTemp) || ! rename($destinationTemp, $destination)) {
                            throw new RuntimeException('ثبت اتمیک فایل روی مقصد ناموفق بود.');
                        }
                    } finally {
                        if (is_file($destinationTemp)) @unlink($destinationTemp);
                    }
                    $uploaded = true;
                    $destinationHash = hash_file('sha256', $destination);
                } else {
                    throw new RuntimeException('درایور مقصد برای انتقال فایل پشتیبانی نمی‌شود.');
                }

                if (! is_string($destinationHash) || ! hash_equals((string) $file->checksum, $destinationHash)) {
                    throw new RuntimeException('Checksum فایل مقصد پس از انتقال با نسخه مبدأ مطابقت ندارد.');
                }

                $newDisk = 'storage_volume:'.$target->id;
                DB::transaction(function () use ($file, $target, $newDisk): void {
                    $lockedFile = StorageManagedFile::query()->lockForUpdate()->findOrFail($file->id);
                    if ($lockedFile->status !== 'transferring' || $lockedFile->storage_volume_id !== null) {
                        throw new RuntimeException('وضعیت فایل هنگام ثبت انتقال تغییر کرده است.');
                    }
                    $lockedFile->update([
                        'storage_volume_id' => $target->id,
                        'status' => 'active',
                        'metadata' => array_merge((array) $lockedFile->metadata, [
                            'disk' => $newDisk,
                            'transferred_at' => now()->toIso8601String(),
                            'transfer_error' => null,
                        ]),
                    ]);
                    $this->syncSourceReference($lockedFile->fresh(), $newDisk);
                    $target->increment('used_bytes', (int) $lockedFile->size_bytes);
                    $target->increment('object_count');
                });

                try {
                    if ($source->exists($file->file_key) && ! $source->delete($file->file_key)) {
                        throw new RuntimeException('پاک‌سازی نسخه موقت پس از انتقال ناموفق بود.');
                    }
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                    $file->update(['metadata' => array_merge((array) $file->fresh()->metadata, [
                        'staging_cleanup_pending' => true,
                        'staging_cleanup_error' => mb_substr($cleanupException->getMessage(), 0, 300),
                    ])]);
                }

                $moved++;
            } catch (\Throwable $exception) {
                if ($uploaded) {
                    try {
                        if ($target->driver === 's3') {
                            $this->s3->delete($target, $file->file_key);
                        } elseif ($target->driver === 'local') {
                            $destination = storage_path('app/storage-volumes/'.$target->id.'/'.StoragePath::normalizeObjectKey($file->file_key));
                            if (is_file($destination) && ! @unlink($destination)) {
                                throw new RuntimeException('پاک‌سازی فایل ناقص مقصد ناموفق بود.');
                            }
                        }
                    } catch (\Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }
                $target->update([
                    'status' => 'error',
                    'last_checked_at' => now(),
                    'metadata' => array_merge((array) $target->metadata, ['last_error' => mb_substr($exception->getMessage(), 0, 400)]),
                ]);
                $file->update([
                    'status' => 'transfer_error',
                    'metadata' => array_merge((array) $file->fresh()->metadata, [
                        'transfer_error' => mb_substr($exception->getMessage(), 0, 400),
                    ]),
                ]);
            } finally {
                @unlink($temp);
            }
        }

        return $moved;
    }
}