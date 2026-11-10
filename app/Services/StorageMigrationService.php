<?php

namespace App\Services;

use App\Models\FarastStorageObject;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Models\StoreProduct;
use App\Models\StoreProductImage;
use App\Models\StorageManagedFile;
use App\Models\TicketMessage;
use App\Models\ConversationMessage;
use App\Models\UserFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageMigrationService
{
    public function migrateLegacy(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $counts = ['product_files' => 0, 'previews' => 0, 'artifacts' => 0, 'product_public_assets' => 0, 'user_files' => 0, 'support_attachments' => 0, 'messenger_attachments' => 0, 'editor_assets' => 0];

        if ($this->hasDedicatedVolume('iran_primary')) {
            $counts['product_files'] += $this->migrateProductFiles($limit);
            $counts['previews'] += $this->migratePreviews($limit);
            $counts['artifacts'] += $this->migrateArtifacts($limit);
            $counts['product_public_assets'] += $this->migrateProductPublicAssets($limit);
        }

        if ($this->hasDedicatedVolume('foreign_primary')) {
            $counts['user_files'] += $this->migrateUserFiles($limit);
            $counts['support_attachments'] += $this->migrateSupportAttachments($limit);
            $counts['messenger_attachments'] += $this->migrateMessengerAttachments($limit);
            $counts['editor_assets'] += $this->migrateEditorAssets($limit);
        }

        return $counts;
    }

    private function hasDedicatedVolume(string $drive): bool
    {
        return \App\Models\StorageVolume::query()
            ->where('drive_key', $drive)
            ->where('driver', '!=', 'local')
            ->where('is_active', true)
            ->where('status', 'healthy')
            ->exists();
    }

    private function migrateProductFiles(int $limit): int
    {
        $count = 0;
        StoreProductFile::query()->where('disk', 'private')->where('is_active', true)->orderBy('id')->limit($limit)->get()
            ->each(function (StoreProductFile $file) use (&$count): void {
                if ($this->copyToManaged($file->disk, $file->path, 'critical', 'product_file', $file->id, $file->product_id, null, $file->original_name, $file->mime)) {
                    $file->update(['disk' => 'managed']);
                    Storage::disk('private')->delete($file->path);
                    $count++;
                }
            });
        return $count;
    }

    private function migratePreviews(int $limit): int
    {
        $count = 0;
        StoreProductPreview::query()->where('disk', 'private')->where('is_active', true)->orderBy('id')->limit($limit)->get()
            ->each(function (StoreProductPreview $preview) use (&$count): void {
                if ($this->copyToManaged('private', $preview->path, 'critical', 'product_preview', $preview->id, $preview->product_id, null, basename($preview->path), $preview->mime)) {
                    $preview->update(['disk' => 'managed']);
                    Storage::disk('private')->delete($preview->path);
                    $count++;
                }
            });

        StoreProductPreview::query()->where('audio_disk', 'private')->whereNotNull('audio_path')->orderBy('id')->limit($limit)->get()
            ->each(function (StoreProductPreview $preview) use (&$count): void {
                if ($this->copyToManaged('private', $preview->audio_path, 'critical', 'product_reader_audio', $preview->id, $preview->product_id, null, basename($preview->audio_path), $preview->audio_mime)) {
                    $preview->update(['audio_disk' => 'managed']);
                    Storage::disk('private')->delete($preview->audio_path);
                    $count++;
                }
            });
        return $count;
    }

    private function migrateArtifacts(int $limit): int
    {
        $count = 0;
        StoreProductDocumentArtifact::query()->where('disk', 'private')->orderBy('id')->limit($limit)->get()
            ->each(function (StoreProductDocumentArtifact $artifact) use (&$count): void {
                $artifact->loadMissing('document');
                $productId = (int) ($artifact->document?->product_id ?? 0);
                if ($this->copyToManaged('private', $artifact->path, 'critical', 'product_document_artifact', $artifact->id, $productId ?: null, null, basename($artifact->path), $artifact->mime)) {
                    $artifact->update(['disk' => 'managed']);
                    Storage::disk('private')->delete($artifact->path);
                    $count++;
                }
            });
        return $count;
    }

    private function migrateProductPublicAssets(int $limit): int
    {
        $count = 0;
        StoreProduct::query()->where('cover_path', 'like', 'storage/store/covers/%')->orderBy('id')->chunkById(100, function ($products) use (&$count, $limit): bool {
            foreach ($products as $product) {
                if ($count >= $limit) return false;
                $path = ltrim(substr((string) $product->cover_path, strlen('storage/')), '/');
                if ($path === '' || StorageManagedFile::query()->where('logical_path', $path)->where('status', 'active')->exists()) continue;
                if ($this->copyToManaged('public', $path, 'critical', 'product_cover', $product->id, $product->id, null, basename($path), null)) {
                    $count++;
                }
            }
            return $count < $limit;
        });

        if ($count < $limit) {
            StoreProductImage::query()->where('path', 'like', 'storage/store/product-images/%')->orderBy('id')->chunkById(100, function ($images) use (&$count, $limit): bool {
                foreach ($images as $image) {
                    if ($count >= $limit) return false;
                    $path = ltrim(substr((string) $image->path, strlen('storage/')), '/');
                    if ($path === '' || StorageManagedFile::query()->where('logical_path', $path)->where('status', 'active')->exists()) continue;
                    if ($this->copyToManaged('public', $path, 'critical', 'product_gallery_image', $image->id, $image->product_id, null, basename($path), null)) {
                        $count++;
                    }
                }
                return $count < $limit;
            });
        }
        return $count;
    }

    private function migrateUserFiles(int $limit): int
    {
        $count = 0;
        UserFile::query()->where('disk', 'private')->whereIn('status', ['local', 'remote'])->orderBy('id')->limit($limit)->get()
            ->each(function (UserFile $file) use (&$count): void {
                if ($file->status === 'remote' && $file->remote_path) {
                    if ($this->migrateArchivedUserFile($file)) $count++;
                    return;
                }
                $sourceDisk = $file->disk ?: 'private';
                $sourcePath = $file->path;
                if ($this->copyToManaged($sourceDisk, $sourcePath, 'non_critical', 'user_file', $file->id, null, $file->user_id, $file->original_name, $file->mime)) {
                    $file->update([
                        'disk' => 'managed',
                        'status' => 'managed',
                        'local_expires_at' => null,
                        'remote_expires_at' => null,
                        'remote_path' => null,
                        'transferred_at' => now(),
                    ]);
                    Storage::disk($sourceDisk)->delete($sourcePath);
                    $count++;
                }
            });
        return $count;
    }

    private function migrateArchivedUserFile(UserFile $file): bool
    {
        $archiveStream = Storage::disk('farast_remote')->readStream($file->remote_path);
        if (! is_resource($archiveStream)) return false;

        $archiveTemp = tmpfile();
        if (! is_resource($archiveTemp)) {
            fclose($archiveStream);
            return false;
        }

        try {
            if (stream_copy_to_stream($archiveStream, $archiveTemp) === false) return false;
            $archivePath = stream_get_meta_data($archiveTemp)['uri'] ?? null;
            if (! is_string($archivePath)) return false;
            $zip = new \ZipArchive();
            if ($zip->open($archivePath) !== true) return false;

            $entryName = $file->original_name;
            if ($zip->locateName($entryName) === false) {
                $entryName = $zip->getNameIndex(0) ?: '';
            }
            if ($entryName === '') {
                $zip->close();
                return false;
            }
            $entryStream = $zip->getStream($entryName);
            if (! is_resource($entryStream)) {
                $zip->close();
                return false;
            }

            $extension = pathinfo($file->original_name, PATHINFO_EXTENSION);
            $newPath = 'user-files/'.$file->user_id.'/'.$file->id.'-'.\Illuminate\Support\Str::uuid().($extension ? '.'.$extension : '');
            try {
                $stored = Storage::disk('managed')->put($newPath, $entryStream, [
                    'farast_classification' => 'non_critical',
                    'farast_source_type' => 'user_file',
                    'farast_source_id' => $file->id,
                    'farast_owner_user_id' => $file->user_id,
                    'farast_original_name' => $file->original_name,
                    'mimetype' => $file->mime,
                ]);
            } finally {
                fclose($entryStream);
                $zip->close();
            }

            if (! $stored) return false;
            $oldArchivePath = $file->remote_path;
            $file->update([
                'disk' => 'managed',
                'path' => $newPath,
                'status' => 'managed',
                'remote_path' => null,
                'local_expires_at' => null,
                'remote_expires_at' => null,
                'transferred_at' => now(),
            ]);
            Storage::disk('farast_remote')->delete($oldArchivePath);
            return true;
        } finally {
            fclose($archiveStream);
            fclose($archiveTemp);
        }
    }

    private function migrateSupportAttachments(int $limit): int
    {
        $count = 0;
        TicketMessage::query()->whereNotNull('attachments')->orderBy('id')->chunkById(100, function ($messages) use (&$count, $limit): bool {
            foreach ($messages as $message) {
                if ($count >= $limit) return false;
                $attachments = (array) ($message->attachments ?? []);
                $changed = false;
                $sourcePathsToDelete = [];
                foreach ($attachments as $index => $attachment) {
                    if ($count >= $limit) break;
                    if (! is_array($attachment) || ($attachment['disk'] ?? 'private') !== 'private' || empty($attachment['path'])) {
                        continue;
                    }
                    $path = (string) $attachment['path'];
                    if (! $this->copyToManaged('private', $path, 'non_critical', 'ticket_attachment', $message->ticket_id, null, $message->user_id, $attachment['name'] ?? basename($path), $attachment['mime'] ?? null)) {
                        continue;
                    }
                    $managed = \App\Models\StorageManagedFile::query()->where('logical_path', $path)->first();
                    $attachments[$index]['disk'] = 'managed';
                    $attachments[$index]['storage_managed_file_id'] = $managed?->id;
                    $changed = true;
                    $sourcePathsToDelete[] = $path;
                    $count++;
                }
                if ($changed) {
                    $message->update(['attachments' => array_values($attachments)]);
                    foreach ($sourcePathsToDelete as $oldPath) {
                        Storage::disk('private')->delete($oldPath);
                    }
                }
            }
            return $count < $limit;
        });
        return $count;
    }

    private function migrateMessengerAttachments(int $limit): int
    {
        $count = 0;
        ConversationMessage::query()->whereNotNull('attachments')->orderBy('id')->chunkById(100, function ($messages) use (&$count, $limit): bool {
            foreach ($messages as $message) {
                if ($count >= $limit) return false;
                $attachments = (array) ($message->attachments ?? []);
                $changed = false;
                $sourcePathsToDelete = [];
                foreach ($attachments as $index => $attachment) {
                    if ($count >= $limit) break;
                    if (! is_array($attachment) || ($attachment['disk'] ?? 'private') !== 'private' || empty($attachment['path'])) {
                        continue;
                    }
                    $path = (string) $attachment['path'];
                    if (! str_starts_with($path, 'messenger/')) continue;
                    if (! $this->copyToManaged('private', $path, 'non_critical', 'messenger_attachment', $message->conversation_id, null, $message->user_id, $attachment['name'] ?? basename($path), $attachment['mime'] ?? null)) {
                        continue;
                    }
                    $managed = StorageManagedFile::query()->where('logical_path', $path)->first();
                    $attachments[$index]['disk'] = 'managed';
                    $attachments[$index]['storage_managed_file_id'] = $managed?->id;
                    $changed = true;
                    $sourcePathsToDelete[] = $path;
                    $count++;
                }
                if ($changed) {
                    $message->update(['attachments' => array_values($attachments)]);
                    foreach ($sourcePathsToDelete as $oldPath) {
                        Storage::disk('private')->delete($oldPath);
                    }
                }
            }
            return $count < $limit;
        });
        return $count;
    }

    private function migrateEditorAssets(int $limit): int
    {
        $count = 0;
        FarastStorageObject::query()
            ->where('disk', 'private')
            ->where('status', 'approved')
            ->where('classification', 'editor_asset')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (FarastStorageObject $object) use (&$count): void {
                if ($this->copyToManaged('private', $object->path, 'non_critical', 'editor_asset', $object->document_id, null, $object->user_id, basename($object->path), $object->mime)) {
                    $object->update(['disk' => 'managed']);
                    Storage::disk('private')->delete($object->path);
                    $count++;
                }
            });
        return $count;
    }

    private function copyToManaged(
        string $sourceDisk,
        string $sourcePath,
        string $classification,
        string $sourceType,
        ?int $sourceId,
        ?int $productId,
        ?int $ownerUserId,
        string $originalName,
        ?string $mime
    ): bool {
        $source = Storage::disk($sourceDisk);
        if (! $source->exists($sourcePath)) {
            return false;
        }

        $stream = $source->readStream($sourcePath);
        if (! is_resource($stream)) {
            return false;
        }

        try {
            $stored = Storage::disk('managed')->put($sourcePath, $stream, [
                'farast_classification' => $classification,
                'farast_source_type' => $sourceType,
                'farast_source_id' => $sourceId,
                'farast_product_id' => $productId,
                'farast_owner_user_id' => $ownerUserId,
                'farast_original_name' => $originalName,
                'mimetype' => $mime,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return false;
        } finally {
            fclose($stream);
        }

        return (bool) $stored;
    }
}
