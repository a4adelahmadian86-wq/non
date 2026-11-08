<?php

namespace App\Http\Controllers;

use App\Models\StoreCourse;
use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductImage;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use App\Services\ProductSupervisorSnapshotBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AdminProductSupervisorController extends Controller
{
    public function review(StoreProduct $product, ProductSupervisorClient $supervisor, ProductSupervisorSnapshotBuilder $snapshotBuilder)
    {
        $settings = data_get($product->metadata, 'ai_supervisor', []);
        abort_unless((bool) data_get($settings, 'enabled', false), 409, 'ناظر هوشمند برای این محصول فعال نیست.');
        abort_unless($supervisor->configured(), 503, 'سرویس مستقل ناظر هنوز متصل نشده است.');

        $snapshot = $snapshotBuilder->build($product, $settings);
        if ($snapshot['checks'] === []) {
            return back()->withErrors(['ai_supervisor' => 'حداقل یک حوزه بررسی را فعال کنید.']);
        }
        $snapshotHash = $snapshotBuilder->fingerprint($snapshot);

        try {
            $review = $supervisor->review($snapshot);
            DB::transaction(function () use ($product, $review, $snapshotHash) {
                $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                $supervisorMeta = data_get($metadata, 'ai_supervisor', []);
                $review['snapshot_hash'] = $snapshotHash;
                $supervisorMeta['last_review'] = $review;
                $supervisorMeta['last_reviewed_at'] = now()->toISOString();
                $metadata['ai_supervisor'] = $supervisorMeta;
                $locked->update(['metadata' => $metadata]);

                app(AuditEventService::class)->record(
                    'store.product.ai_supervisor_reviewed',
                    auth()->id(),
                    null,
                    'store_product',
                    $locked->id,
                    [
                        'review_id' => $review['review_id'],
                        'verdict' => $review['verdict'],
                        'score' => $review['score'],
                        'model' => $review['model'] ?? null,
                    ],
                    'admin_console'
                );
            });
        } catch (Throwable $exception) {
            Log::warning('Product supervisor review failed', [
                'product_id' => $product->id,
                'exception' => class_basename($exception),
            ]);

            return back()->withErrors(['ai_supervisor' => 'بررسی ناظر انجام نشد؛ اتصال سرویس و تنظیمات آن را بررسی و دوباره تلاش کنید.']);
        }

        return back()->with('status', 'بررسی ناظر هوشمند تکمیل شد؛ نتیجه برای بازبینی مدیر ذخیره شد.');
    }

    public function reviewFile(StoreProduct $product, StoreProductFile $file, ProductSupervisorClient $supervisor)
    {
        abort_unless((int) $file->product_id === (int) $product->id && $file->purpose === 'product_download' && $file->is_active, 404);
        abort_unless($file->scan_status === 'clean', 409, 'فایل باید ابتدا از اسکنر اصلی فروشگاه وضعیت سالم دریافت کند.');

        return $this->reviewStoredAsset(
            $product,
            $supervisor,
            $file->disk ?: 'private',
            $file->path,
            $file->original_name ?: basename($file->path),
            'file:'.$file->id,
            $file->sha256
        );
    }


    public function reviewCourseResource(
        StoreProduct $product,
        StoreCourseLesson $lesson,
        StoreProductFile $file,
        ProductSupervisorClient $supervisor
    ) {
        abort_unless($product->type === 'course', 404);
        $course = StoreCourse::query()->where('product_id', $product->id)->firstOrFail();
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        abort_unless(
            (int) $file->product_id === (int) $product->id
            && (int) $file->course_lesson_id === (int) $lesson->id
            && $file->purpose === 'lesson_resource'
            && $file->is_active
            && $file->scan_status === 'clean',
            404
        );

        return $this->reviewStoredAsset(
            $product,
            $supervisor,
            $file->disk ?: 'private',
            $file->path,
            $file->original_name ?: basename($file->path),
            'lesson-resource:'.$file->id,
            $file->sha256
        );
    }

    public function reviewImage(StoreProduct $product, StoreProductImage $image, ProductSupervisorClient $supervisor)
    {
        abort_unless((int) $image->product_id === (int) $product->id && $image->is_active, 404);
        abort_unless(Str::startsWith((string) $image->path, 'storage/store/product-images/'.$product->id.'/'), 404);

        return $this->reviewStoredAsset(
            $product,
            $supervisor,
            'public',
            Str::after($image->path, 'storage/'),
            basename($image->path),
            'image:'.$image->id
        );
    }

    public function reviewCover(StoreProduct $product, ProductSupervisorClient $supervisor)
    {
        $path = (string) $product->cover_path;
        abort_unless($path !== '' && Str::startsWith($path, 'storage/store/covers/'.$product->id.'/'), 404);

        return $this->reviewStoredAsset(
            $product,
            $supervisor,
            'public',
            Str::after($path, 'storage/'),
            basename($path),
            'cover'
        );
    }

    private function reviewStoredAsset(
        StoreProduct $product,
        ProductSupervisorClient $supervisor,
        string $disk,
        string $path,
        string $filename,
        string $assetKey,
        ?string $expectedSha256 = null
    ) {
        $settings = (array) data_get($product->metadata, 'ai_supervisor', []);
        abort_unless((bool) data_get($settings, 'enabled', false), 409, 'ناظر هوشمند برای این محصول فعال نیست.');
        abort_unless($supervisor->configured(), 503, 'سرویس مستقل ناظر هنوز متصل نشده است.');

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404, 'فایل موردنظر در فضای ذخیره‌سازی پیدا نشد.');
        abort_if($storage->size($path) > 25 * 1024 * 1024, 413, 'برای بررسی هوشمند، حجم هر فایل حداکثر ۲۵ مگابایت است.');
        $stream = $storage->readStream($path);
        if (! is_resource($stream)) {
            return back()->withErrors(['ai_supervisor' => 'خواندن امن فایل برای بررسی ممکن نشد.']);
        }

        try {
            $hashContext = hash_init('sha256');
            hash_update_stream($hashContext, $stream);
            $sha256 = hash_final($hashContext);
            rewind($stream);

            if ($expectedSha256 && ! hash_equals(strtolower($expectedSha256), strtolower($sha256))) {
                throw new \RuntimeException('product_asset_checksum_mismatch');
            }

            $product->loadMissing(['category', 'tags']);
            $review = $supervisor->reviewFile($stream, $filename, [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'category_name' => $product->category?->name,
                'tags_json' => json_encode($product->tags->pluck('name')->take(30)->values()->all(), JSON_UNESCAPED_UNICODE),
                'description' => mb_substr((string) $product->description, 0, 8000),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Product supervisor asset review failed', [
                'product_id' => $product->id,
                'asset_key' => $assetKey,
                'exception' => class_basename($exception),
            ]);

            return back()->withErrors(['ai_supervisor' => 'بررسی فایل انجام نشد؛ وضعیت سرویس و سلامت فایل را بررسی کنید.']);
        } finally {
            fclose($stream);
        }

        $review['sha256'] = $sha256;
        $review['reviewed_at'] = now()->toISOString();

        DB::transaction(function () use ($product, $review, $assetKey) {
            $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $supervisorMeta = (array) data_get($metadata, 'ai_supervisor', []);
            $assetReviews = (array) data_get($supervisorMeta, 'asset_reviews', []);
            $assetReviews[$assetKey] = $review;
            $supervisorMeta['asset_reviews'] = $assetReviews;
            $metadata['ai_supervisor'] = $supervisorMeta;
            $locked->update(['metadata' => $metadata]);

            app(AuditEventService::class)->record(
                'store.product.ai_supervisor_asset_reviewed',
                auth()->id(),
                null,
                'store_product',
                $locked->id,
                [
                    'review_id' => $review['review_id'],
                    'asset_key' => $assetKey,
                    'detected_type' => data_get($review, 'inspection.detected_type'),
                    'security_gate' => $review['security_gate'],
                ],
                'admin_console'
            );
        });

        return back()->with('status', 'بررسی محتوای فایل تکمیل شد؛ گزارش آن کنار همان دارایی ذخیره شد.');
    }

}
