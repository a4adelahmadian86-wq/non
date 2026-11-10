<?php

namespace App\Services\Storage;

use App\Models\StorageManagedFile;
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

    private function syncSourceReference(StorageManagedFile $file, string $disk): void
    {
        if (! $file->source_id || ! $file->source_type) return;
        $reference = match ($file->source_type) {
            UserFile::class => UserFile::find($file->source_id),
            StoreProductFile::class => StoreProductFile::find($file->source_id),
            ConversationMessage::class => ConversationMessage::find($file->source_id),
            TicketMessage::class => TicketMessage::find($file->source_id),
            default => null,
        };
        if (! $reference) return;

        if ($reference instanceof UserFile) {
            $reference->update([
                'disk' => $disk, 'path' => $file->file_key, 'status' => 'remote',
                'remote_path' => $file->file_key, 'local_expires_at' => null,
                'remote_expires_at' => null, 'transferred_at' => now(),
            ]);
            return;
        }
        if ($reference instanceof StoreProductFile) {
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

    public function transferPending(int $limit = 100): int
    {
        $moved = 0;
        StorageManagedFile::query()->whereIn('status', ['pending_transfer', 'transfer_error'])->orderBy('id')->limit(max(1, $limit))->get()->each(function (StorageManagedFile $file) use (&$moved) {
            if (data_get($file->metadata, 'reference_pending', false)) return;
            $targetDrive = (string) data_get($file->metadata, 'target_drive', $file->drive_key);
            $target = StorageVolume::query()
                ->where('drive_key', $targetDrive)
                ->where('is_active', true)
                ->where('status', 'healthy')
                ->where(fn ($q) => $q->whereNull('capacity_bytes')->orWhereRaw('(capacity_bytes - used_bytes) >= ?', [(int) $file->size_bytes]))
                ->where(fn ($q) => $q->whereNull('max_file_bytes')->orWhere('max_file_bytes', '>=', (int) $file->size_bytes))
                ->where(fn ($q) => $q->whereNull('file_limit')->orWhereRaw('(SELECT COUNT(*) FROM storage_managed_files WHERE storage_managed_files.storage_volume_id = storage_volumes.id AND storage_managed_files.status != ?) < storage_volumes.file_limit', ['deleted']))
                ->orderBy('priority')->first();
            if (! $target) return;
            $sourceDisk = (string) data_get($file->metadata, 'staging_disk', 'private');
            $source = Storage::disk($sourceDisk);
            if (! $source->exists($file->file_key)) return;
            $temp = tempnam(sys_get_temp_dir(), 'farast-transfer');
            $uploaded = false;
            try {
                $stream = $source->readStream($file->file_key);
                if (! is_resource($stream)) throw new RuntimeException('خواندن جریان فایل موقت ناموفق بود.');
                $out = fopen($temp, 'wb');
                if (stream_copy_to_stream($stream, $out) === false) throw new RuntimeException('انتقال جریان فایل موقت ناموفق بود.');
                fclose($out);
                fclose($stream);
                if ($target->driver === 's3') {
                    $this->s3->putFile($target, $file->file_key, $temp);
                    $uploaded = true;
                    $remoteHash = hash_file('sha256', $temp);
                    if (! hash_equals((string) $file->checksum, $remoteHash)) throw new RuntimeException('بررسی checksum فایل انتقال‌یافته ناموفق بود.');
                } elseif ($target->driver === 'local') {
                    $root = storage_path('app/storage-volumes/'.$target->id);
                    $destination = $root.'/'.$file->file_key;
                    if (! is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
                    if (! copy($temp, $destination)) throw new RuntimeException('کپی فایل روی مقصد ناموفق بود.');
                    $uploaded = true;
                } else {
                    return;
                }
                if (! hash_equals((string) $file->checksum, (string) hash_file('sha256', $temp))) {
                    throw new RuntimeException('بررسی checksum فایل مبدأ ناموفق بود.');
                }
                $newDisk = 'storage_volume:'.$target->id;
                DB::transaction(function () use ($file, $target, $newDisk) {
                    $file->update(['storage_volume_id' => $target->id, 'status' => 'active', 'metadata' => array_merge((array) $file->metadata, ['disk' => $newDisk, 'transferred_at' => now()->toIso8601String()])]);
                    $this->syncSourceReference($file, $newDisk);
                    $target->increment('used_bytes', (int) $file->size_bytes);
                    $target->increment('object_count');
                });
                $source->delete($file->file_key);
                $moved++;
            } catch (\Throwable $exception) {
                if ($uploaded) {
                    try {
                        if ($target->driver === 's3') $this->s3->delete($target, $file->file_key);
                        elseif ($target->driver === 'local') @unlink(storage_path('app/storage-volumes/'.$target->id.'/'.$file->file_key));
                    } catch (\Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }
                $file->update(['status' => 'transfer_error', 'metadata' => array_merge((array) $file->metadata, ['transfer_error' => mb_substr($exception->getMessage(), 0, 400)])]);
            } finally {
                @unlink($temp);
            }
        });
        return $moved;
    }
}