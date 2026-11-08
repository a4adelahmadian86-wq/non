<?php

namespace App\Services;

use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;

class ProductSupervisorSnapshotBuilder
{
    public function build(StoreProduct $product, array $settings): array
    {
        $product->loadMissing(['category', 'tags']);
        $checks = collect((array) ($settings['checks'] ?? []))
            ->filter(fn ($enabled) => (bool) $enabled)
            ->keys()
            ->filter(fn ($key) => in_array($key, ['content', 'pricing', 'seo', 'media', 'policy', 'course'], true))
            ->values()
            ->all();

        $publishableLessons = 0;
        if ($product->type === 'course') {
            $courseId = $product->course()->value('id');
            if ($courseId) {
                $publishableLessons = StoreCourseLesson::query()
                    ->whereHas('section', fn ($query) => $query->where('course_id', $courseId))
                    ->publishable()
                    ->count();
            }
        }

        return [
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
            'instructions' => mb_substr(trim((string) ($settings['instructions'] ?? '')), 0, 2000),
        ];
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
