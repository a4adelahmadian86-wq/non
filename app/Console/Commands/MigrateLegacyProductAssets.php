<?php

namespace App\Console\Commands;

use App\Models\StoreProduct;
use App\Models\StoreProductImage;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class MigrateLegacyProductAssets extends Command
{
    protected $signature = 'storage:migrate-legacy-product-assets {--limit=100}';
    protected $description = 'Move legacy public product covers and gallery images into the central Iranian storage drive';

    public function handle(StorageManager $storage): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $migrated = 0;
        $failed = 0;

        StoreProduct::query()
            ->whereNotNull('cover_path')
            ->where('cover_path', 'like', 'storage/store/covers/%')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (StoreProduct $product) use ($storage, &$migrated, &$failed): void {
                try {
                    if ($this->migrateCover($product, $storage)) $migrated++;
                } catch (\Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn('کاور محصول #'.$product->id.' منتقل نشد: '.$exception->getMessage());
                }
            });

        $remaining = max(0, $limit - $migrated - $failed);
        if ($remaining > 0) {
            StoreProductImage::query()
                ->where('path', 'like', 'storage/store/product-images/%')
                ->orderBy('id')
                ->limit($remaining)
                ->get()
                ->each(function (StoreProductImage $image) use ($storage, &$migrated, &$failed): void {
                    try {
                        if ($this->migrateImage($image, $storage)) $migrated++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        report($exception);
                        $this->warn('تصویر محصول #'.$image->id.' منتقل نشد: '.$exception->getMessage());
                    }
                });
        }

        $this->info("Migrated: {$migrated}; failed: {$failed}. Run the command again to continue remaining legacy assets.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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

        $upload = new UploadedFile($absolute, basename($relative), $mime, UPLOAD_ERR_OK, true);
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

        $upload = new UploadedFile($absolute, basename($relative), $mime, UPLOAD_ERR_OK, true);
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
