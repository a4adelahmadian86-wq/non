<?php

namespace App\Console\Commands;

use App\Models\StoreProduct;
use App\Models\StoreProductImage;
use App\Models\StoreProductFile;
use App\Models\StoreProductPreview;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StorageManagedFile;
use App\Services\Storage\StorageManager;
use App\Services\Storage\StorageTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class MigrateLegacyProductAssets extends Command
{
    protected $signature = 'storage:migrate-legacy-product-assets {--limit=100}';
    protected $description = 'Move legacy product downloads, reader artifacts, previews, covers and gallery images into central storage';

    public function handle(StorageManager $storage, StorageTransferService $transfer): int
    {
        $remaining = max(1, min(1000, (int) $this->option('limit')));
        $migrated = 0;
        $failed = 0;

        foreach (StoreProductDocumentArtifact::query()->where(fn ($q) => $q->whereNull('disk')->orWhereIn('disk', ['private', 'public']))->orderBy('id')->cursor() as $artifact) {
            if ($remaining < 1) break;
            if ($this->hasManagedReference(StoreProductDocumentArtifact::class, (int) $artifact->id)) continue;
            $this->attemptMigration(fn () => $this->migrateArtifact($artifact, $storage, $transfer), 'سند تولیدشده محصول', $artifact->id, $remaining, $migrated, $failed);
        }

        foreach (StoreProductFile::query()->where(fn ($q) => $q->whereNull('disk')->orWhere('disk', 'private'))->orderBy('id')->cursor() as $file) {
            if ($remaining < 1) break;
            if ($this->hasManagedReference(StoreProductFile::class, (int) $file->id)) continue;
            $this->attemptMigration(fn () => $this->migrateProductFile($file, $storage, $transfer), 'فایل محصول', $file->id, $remaining, $migrated, $failed);
        }

        foreach (StoreProductPreview::query()->orderBy('id')->cursor() as $preview) {
            if ($remaining < 1) break;
            foreach (['path', 'audio_path'] as $field) {
                if ($remaining < 1) break;
                $disk = $field === 'audio_path' ? ($preview->audio_disk ?: 'private') : ($preview->disk ?: 'private');
                $path = $field === 'audio_path' ? $preview->audio_path : $preview->path;
                if (! $path || $disk !== 'private' || $this->hasManagedReference(StoreProductPreview::class, (int) $preview->id, $field)) continue;
                $this->attemptMigration(fn () => $this->migratePreviewAsset($preview, $field, $storage, $transfer), 'پیش‌نمایش محصول', $preview->id, $remaining, $migrated, $failed);
            }
        }

        foreach (StoreProduct::query()->whereNotNull('cover_path')->where('cover_path', 'like', 'storage/store/covers/%')->orderBy('id')->cursor() as $product) {
            if ($remaining < 1) break;
            $this->attemptMigration(fn () => $this->migrateCover($product, $storage), 'کاور محصول', $product->id, $remaining, $migrated, $failed);
        }

        foreach (StoreProductImage::query()->where('path', 'like', 'storage/store/product-images/%')->orderBy('id')->cursor() as $image) {
            if ($remaining < 1) break;
            $this->attemptMigration(fn () => $this->migrateImage($image, $storage), 'تصویر محصول', $image->id, $remaining, $migrated, $failed);
        }

        $this->info("Migrated: {$migrated}; failed: {$failed}. Run the command again to continue remaining legacy product assets.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function attemptMigration(callable $callback, string $label, int $id, int &$remaining, int &$migrated, int &$failed): void
    {
        $remaining--;
        try {
            if ($callback()) $migrated++;
        } catch (\Throwable $exception) {
            $failed++;
            report($exception);
            $this->warn($label.' #'.$id.' منتقل نشد: '.$exception->getMessage());
        }
    }

    private function hasManagedReference(string $sourceType, int $sourceId, string $sourceField = 'path'): bool
    {
        $query = StorageManagedFile::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNotIn('status', ['deleted']);
        if ($sourceField === 'path') {
            $query->where(fn ($q) => $q->whereNull('metadata->source_field')->orWhere('metadata->source_field', 'path'));
        } else {
            $query->where('metadata->source_field', $sourceField);
        }
        return $query->exists();
    }

    private function migrateArtifact(StoreProductDocumentArtifact $artifact, StorageManager $storage, StorageTransferService $transfer): bool
    {
        $document = $artifact->document()->first();
        if (! $document) throw new RuntimeException('سند والد برای فایل تولیدشده پیدا نشد.');
        $legacyPath = (string) $artifact->path;
        $legacyDiskName = $artifact->disk ?: 'private';
        if (! in_array($legacyDiskName, ['private', 'public'], true)) return false;
        $disk = Storage::disk($legacyDiskName);
        if (! $disk->exists($legacyPath)) throw new RuntimeException('فایل تولیدشده قدیمی روی دیسک مبدأ پیدا نشد.');
        $absolute = $disk->path($legacyPath);
        $mime = (string) ($artifact->mime ?: ($disk->mimeType($legacyPath) ?: 'application/octet-stream'));
        $upload = new UploadedFile($absolute, basename($legacyPath), $mime, UPLOAD_ERR_OK, true);
        $managed = $storage->storeUploadedFile($upload, StorageManager::CRITICAL, 'store-product-artifact', StoreProductDocumentArtifact::class, (int) $artifact->id, (int) $document->product_id, 'private');

        try {
            DB::transaction(function () use ($artifact, $legacyPath, $legacyDiskName, $managed, $document, $storage, $transfer): void {
                $locked = StoreProductDocumentArtifact::query()->whereKey($artifact->id)->lockForUpdate()->firstOrFail();
                if ((string) $locked->path !== $legacyPath || ($locked->disk ?: 'private') !== $legacyDiskName) throw new RuntimeException('فایل تولیدشده هم‌زمان تغییر کرده است.');
                $storage->bindReference((int) $managed['managed_file_id'], StoreProductDocumentArtifact::class, (int) $locked->id, (int) $document->product_id);
                $transfer->syncSourceReference(StorageManagedFile::findOrFail($managed['managed_file_id']), $managed['disk']);
            });
        } catch (\\Throwable $exception) {
            $storage->delete($managed['disk'], $managed['path']);
            throw $exception;
        }

        $this->deleteLegacyAssetIfUnreferenced($legacyDiskName, $legacyPath);
        return true;
    }

    private function migrateProductFile(StoreProductFile $file, StorageManager $storage, StorageTransferService $transfer): bool
    {
        $legacyPath = (string) $file->path;
        $legacyDiskName = $file->disk ?: 'private';
        if (! in_array($legacyDiskName, ['private', 'public'], true)) return false;
        $disk = Storage::disk($legacyDiskName);
        if (! $disk->exists($legacyPath)) throw new RuntimeException('فایل دانلود قدیمی محصول روی دیسک مبدأ پیدا نشد.');
        $absolute = $disk->path($legacyPath);
        $mime = (string) ($file->mime ?: ($disk->mimeType($legacyPath) ?: 'application/octet-stream'));
        $upload = new UploadedFile($absolute, (string) ($file->original_name ?: basename($legacyPath)), $mime, UPLOAD_ERR_OK, true);
        $managed = $storage->storeUploadedFile($upload, StorageManager::CRITICAL, 'store-product-file', StoreProductFile::class, (int) $file->id, (int) $file->product_id, 'private');

        try {
            DB::transaction(function () use ($file, $legacyPath, $legacyDiskName, $managed, $storage, $transfer): void {
                $locked = StoreProductFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
                if ((string) $locked->path !== $legacyPath || ($locked->disk ?: 'private') !== $legacyDiskName) throw new RuntimeException('فایل محصول هم‌زمان تغییر کرده است.');
                $storage->bindReference((int) $managed['managed_file_id'], StoreProductFile::class, (int) $locked->id, (int) $locked->product_id);
                $transfer->syncSourceReference(StorageManagedFile::findOrFail($managed['managed_file_id']), $managed['disk']);
            });
        } catch (\\Throwable $exception) {
            $storage->delete($managed['disk'], $managed['path']);
            throw $exception;
        }

        $this->deleteLegacyAssetIfUnreferenced($legacyDiskName, $legacyPath);
        return true;
    }

    private function migratePreviewAsset(StoreProductPreview $preview, string $field, StorageManager $storage, StorageTransferService $transfer): bool
    {
        $isAudio = $field === 'audio_path';
        $legacyPath = (string) ($isAudio ? $preview->audio_path : $preview->path);
        $legacyDiskName = $isAudio ? ($preview->audio_disk ?: 'private') : ($preview->disk ?: 'private');
        if ($legacyPath === '' || ! in_array($legacyDiskName, ['private', 'public'], true)) return false;
        $disk = Storage::disk($legacyDiskName);
        if (! $disk->exists($legacyPath)) throw new RuntimeException('فایل قدیمی پیش‌نمایش روی دیسک مبدأ پیدا نشد.');
        $absolute = $disk->path($legacyPath);
        $mime = (string) (($isAudio ? $preview->audio_mime : $preview->mime) ?: ($disk->mimeType($legacyPath) ?: 'application/octet-stream'));
        $upload = new UploadedFile($absolute, basename($legacyPath), $mime, UPLOAD_ERR_OK, true);
        $managed = $storage->storeUploadedFile($upload, StorageManager::CRITICAL, 'store-product-preview', StoreProductPreview::class, (int) $preview->id, (int) $preview->product_id, 'private');

        try {
            DB::transaction(function () use ($preview, $field, $legacyPath, $legacyDiskName, $managed, $storage, $transfer): void {
                $locked = StoreProductPreview::query()->whereKey($preview->id)->lockForUpdate()->firstOrFail();
                $currentPath = $field === 'audio_path' ? $locked->audio_path : $locked->path;
                $currentDisk = $field === 'audio_path' ? ($locked->audio_disk ?: 'private') : ($locked->disk ?: 'private');
                if ((string) $currentPath !== $legacyPath || $currentDisk !== $legacyDiskName) throw new RuntimeException('پیش‌نمایش هم‌زمان تغییر کرده است.');
                $storage->bindReference((int) $managed['managed_file_id'], StoreProductPreview::class, (int) $locked->id, (int) $locked->product_id, $field);
                $transfer->syncSourceReference(StorageManagedFile::findOrFail($managed['managed_file_id']), $managed['disk']);
            });
        } catch (\\Throwable $exception) {
            $storage->delete($managed['disk'], $managed['path']);
            throw $exception;
        }

        $this->deleteLegacyAssetIfUnreferenced($legacyDiskName, $legacyPath);
        return true;
    }

    private function deleteLegacyAssetIfUnreferenced(string $diskName, string $path): void
    {
        $previewReferences = StoreProductPreview::query()
            ->where(fn ($query) => $query->where('path', $path)->orWhere('audio_path', $path))
            ->get()
            ->contains(fn (StoreProductPreview $preview) =>
                ((string) $preview->path === $path && ($preview->disk ?: 'private') === $diskName)
                || ((string) $preview->audio_path === $path && ($preview->audio_disk ?: 'private') === $diskName)
            );
        $artifactReferences = StoreProductDocumentArtifact::query()
            ->where('path', $path)
            ->get()
            ->contains(fn (StoreProductDocumentArtifact $artifact) => ($artifact->disk ?: 'private') === $diskName);
        $fileReferences = StoreProductFile::query()
            ->where('path', $path)
            ->get()
            ->contains(fn (StoreProductFile $file) => ($file->disk ?: 'private') === $diskName);

        if (! $previewReferences && ! $artifactReferences && ! $fileReferences) {
            Storage::disk($diskName)->delete($path);
        }
    }

    private function migrateCover(StoreProduct $product, StorageManager $storage): bool
    {
        $legacyPath = (string) $product->cover_path;
        $relative = Str::after($legacyPath, 'storage/');
        $disk = Storage::disk('public');
        if (! $disk->exists($relative)) throw new RuntimeException('فایل کاور قدیمی روی فضای عمومی پیدا نشد.');
        $absolute = $disk->path($relative);
        $mime = (string) ($disk->mimeType($relative) ?: mime_content_type($absolute));
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('نوع تصویر کاور قدیمی پشتیبانی نمی‌شود.');

        $upload = new UploadedFile($absolute, 'product-'.$product->id.'-cover.'.pathinfo($relative, PATHINFO_EXTENSION), $mime, UPLOAD_ERR_OK, true);
        $managed = $storage->storeUploadedFile($upload, StorageManager::CRITICAL, 'store-cover', StoreProduct::class, (int) $product->id, (int) $product->id, 'public');
        $newPath = 'storage/managed/'.$managed['managed_file_id'];

        try {
            DB::transaction(function () use ($product, $legacyPath, $newPath, $managed, $storage): void {
                $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ((string) $locked->cover_path !== $legacyPath) throw new RuntimeException('کاور محصول هم‌زمان توسط کاربر دیگری تغییر کرده است.');
                $locked->update(['cover_path' => $newPath]);
                $storage->bindReference((int) $managed['managed_file_id'], StoreProduct::class, (int) $locked->id, (int) $locked->id);
            });
        } catch (\Throwable $exception) {
            $storage->delete((string) $managed['disk'], (string) $managed['path']);
            throw $exception;
        }

        $disk->delete($relative);
        return true;
    }

    private function migrateImage(StoreProductImage $image, StorageManager $storage): bool
    {
        $legacyPath = (string) $image->path;
        $relative = Str::after($legacyPath, 'storage/');
        $disk = Storage::disk('public');
        if (! $disk->exists($relative)) throw new RuntimeException('فایل تصویر قدیمی روی فضای عمومی پیدا نشد.');
        $absolute = $disk->path($relative);
        $mime = (string) ($disk->mimeType($relative) ?: mime_content_type($absolute));
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new RuntimeException('نوع تصویر قدیمی پشتیبانی نمی‌شود.');

        $upload = new UploadedFile($absolute, 'product-'.$image->product_id.'-image-'.$image->id.'.'.pathinfo($relative, PATHINFO_EXTENSION), $mime, UPLOAD_ERR_OK, true);
        $managed = $storage->storeUploadedFile($upload, StorageManager::CRITICAL, 'store-product-image', StoreProductImage::class, (int) $image->id, (int) $image->product_id, 'public');
        $newPath = 'storage/managed/'.$managed['managed_file_id'];

        try {
            DB::transaction(function () use ($image, $legacyPath, $newPath, $managed, $storage): void {
                $locked = StoreProductImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
                if ((string) $locked->path !== $legacyPath) throw new RuntimeException('تصویر محصول هم‌زمان توسط کاربر دیگری تغییر کرده است.');
                $locked->update(['path' => $newPath]);
                $storage->bindReference((int) $managed['managed_file_id'], StoreProductImage::class, (int) $locked->id, (int) $locked->product_id);
            });
        } catch (\Throwable $exception) {
            $storage->delete((string) $managed['disk'], (string) $managed['path']);
            throw $exception;
        }

        $disk->delete($relative);
        return true;
    }
}
