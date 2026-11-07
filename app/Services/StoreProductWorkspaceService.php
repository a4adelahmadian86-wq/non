<?php

namespace App\\Services;

use App\\Models\\StoreProduct;

class StoreProductWorkspaceService
{
    /**
     * A single source of truth for product publishing readiness across admin screens.
     */
    public function inspect(StoreProduct $product): array
    {
        $product->loadMissing(['files', 'course.sections.lessons']);

        $isCourse = $product->type === 'course';
        $primaryFile = $product->files->first(fn ($file) =>
            $file->purpose === 'product_download'
            && $file->is_primary
            && $file->is_active
            && $file->scan_status === 'clean'
        );

        $lessons = $product->course?->sections?->flatMap(fn ($section) => $section->lessons) ?? collect();
        $publishedLessons = $lessons->where('is_published', true);
        $previewLessons = $lessons->where('is_preview', true)->where('is_published', true);

        $checks = [
            ['key' => 'identity', 'label' => 'اطلاعات پایه و شناسه محصول', 'done' => filled($product->title) && filled($product->sku) && filled($product->category_id)],
            ['key' => 'price', 'label' => 'قیمت‌گذاری محصول', 'done' => $product->price_rials !== null && $product->price_rials >= 0],
            ['key' => 'cover', 'label' => 'تصویر کاور', 'done' => filled($product->cover_path), 'optional' => true],
            ['key' => 'content', 'label' => $isCourse ? 'حداقل یک جلسه منتشرشده' : 'فایل اصلی سالم و اسکن‌شده', 'done' => $isCourse ? $publishedLessons->isNotEmpty() : (bool) $primaryFile],
        ];

        if ($isCourse) {
            $checks[] = ['key' => 'preview', 'label' => 'جلسه نمونه رایگان', 'done' => $previewLessons->isNotEmpty(), 'optional' => true];
        }

        $required = collect($checks)->reject(fn ($check) => $check['optional'] ?? false);
        $completed = $required->where('done', true)->count();

        return [
            'checks' => $checks,
            'required_count' => $required->count(),
            'completed_count' => $completed,
            'percentage' => $required->isEmpty() ? 0 : (int) round(($completed / $required->count()) * 100),
            'can_publish' => $required->every(fn ($check) => $check['done']),
            'published_lessons' => $publishedLessons->count(),
            'total_lessons' => $lessons->count(),
            'preview_lessons' => $previewLessons->count(),
            'primary_file' => $primaryFile,
            'cover_ready' => filled($product->cover_path),
        ];
    }
}
