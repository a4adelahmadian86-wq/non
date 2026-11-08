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

        $mediaParts = [
            'cover' => $product->cover_path,
            'images' => $product->images()->orderBy('id')->get(['id', 'path', 'alt', 'is_active'])->toArray(),
            'previews' => $product->previews()->orderBy('id')->get(['id', 'path', 'mime', 'page_number', 'kind', 'watermarked', 'is_active'])->toArray(),
            'files' => $product->files()
                ->where('purpose', 'product_download')
                ->orderBy('id')
                ->get(['id', 'sha256', 'is_primary', 'is_active', 'scan_status'])
                ->toArray(),
        ];
        $mediaFingerprint = $this->fingerprint($mediaParts);

        $publishableLessons = 0;
        $courseFingerprint = null;
        if ($product->type === 'course') {
            $courseId = $product->course()->value('id');
            if ($courseId) {
                $courseLessons = StoreCourseLesson::query()
                    ->whereHas('section', fn ($query) => $query->where('course_id', $courseId))
                    ->with(['resources:id,course_lesson_id,sha256,is_active,scan_status,mime'])
                    ->orderBy('id')
                    ->get(['id', 'section_id', 'title', 'lesson_type', 'content', 'video_url', 'is_published']);
                $courseFingerprint = $this->fingerprint($courseLessons->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'section_id' => $lesson->section_id,
                    'title' => $lesson->title,
                    'lesson_type' => $lesson->lesson_type,
                    'content' => $lesson->content,
                    'video_url' => $lesson->video_url,
                    'is_published' => $lesson->is_published,
                    'resources' => $lesson->resources->map(fn ($resource) => [
                        'id' => $resource->id,
                        'sha256' => $resource->sha256,
                        'is_active' => $resource->is_active,
                        'scan_status' => $resource->scan_status,
                        'mime' => $resource->mime,
                    ])->all(),
                ])->all());
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
            'description' => mb_substr((string) $product->description, 0, 8000),
            'description_truncated' => mb_strlen((string) $product->description) > 8000,
            'price_rials' => (int) $product->price_rials,
            'compare_at_price_rials' => $product->compare_at_price_rials,
            'download_policy' => $product->download_policy ?? 'signed',
            'seo_title' => $product->seo_title,
            'seo_description' => $product->seo_description,
            'category_name' => $product->category?->name,
            'tags' => $product->tags->pluck('name')->take(30)->values()->all(),
            'has_cover' => filled($product->cover_path),
            'media_fingerprint' => $mediaFingerprint,
            'course_fingerprint' => $courseFingerprint,
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
