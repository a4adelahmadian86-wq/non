<?php

namespace App\Http\Controllers;

use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminProductSupervisorController extends Controller
{
    public function review(Request $request, StoreProduct $product, ProductSupervisorClient $supervisor)
    {
        $settings = data_get($product->metadata, 'ai_supervisor', []);
        abort_unless((bool) data_get($settings, 'enabled', false), 409, 'ناظر هوشمند برای این محصول فعال نیست.');
        abort_unless($supervisor->configured(), 503, 'سرویس مستقل ناظر هنوز متصل نشده است.');

        $checks = collect((array) data_get($settings, 'checks', []))
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->filter(fn ($key) => in_array($key, ['content', 'pricing', 'seo', 'media', 'policy', 'course'], true))
            ->values()
            ->all();
        if ($checks === []) {
            return back()->withErrors(['ai_supervisor' => 'حداقل یک حوزه بررسی را فعال کنید.']);
        }

        $product->loadMissing(['category', 'tags', 'course']);
        $publishableLessons = 0;
        if ($product->type === 'course' && $product->course) {
            $publishableLessons = StoreCourseLesson::query()
                ->whereHas('section', fn ($query) => $query->where('course_id', $product->course->id))
                ->publishable()
                ->count();
        }

        $snapshot = [
            'product_id' => (string) $product->id,
            'product_type' => $product->type,
            'title' => $product->title,
            'sku' => $product->sku,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'price_rials' => (int) $product->price_rials,
            'compare_at_price_rials' => $product->compare_at_price_rials,
            'seo_title' => $product->seo_title,
            'seo_description' => $product->seo_description,
            'category_name' => $product->category?->name,
            'tags' => $product->tags->pluck('name')->take(30)->values()->all(),
            'has_cover' => filled($product->cover_path),
            'active_image_count' => $product->images()->where('is_active', true)->count(),
            'has_clean_primary_file' => $product->files()
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->where('scan_status', 'clean')
                ->exists(),
            'publishable_lesson_count' => $publishableLessons,
            'checks' => $checks,
            'instructions' => mb_substr(trim((string) data_get($settings, 'instructions', '')), 0, 2000),
        ];

        try {
            $review = $supervisor->review($snapshot);
            DB::transaction(function () use ($product, $review) {
                $locked = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $metadata = $locked->metadata ?? [];
                $supervisorMeta = data_get($metadata, 'ai_supervisor', []);
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
}
