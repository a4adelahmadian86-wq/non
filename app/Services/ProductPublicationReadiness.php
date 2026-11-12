<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductPublicationReadiness
{
    public function publicationBlockers(StoreProduct $product): array
    {
        $blockers = [];

        if (! filled($product->title)) {
            $blockers[] = 'عنوان محصول ثبت نشده است.';
        }
        if (! $product->category || ! $product->category->is_active) {
            $blockers[] = 'دسته‌بندی فعال برای محصول انتخاب نشده است.';
        }
        if ($product->price_rials === null || (int) $product->price_rials < 0) {
            $blockers[] = 'قیمت معتبر برای محصول ثبت نشده است.';
        }

        if ($product->type === 'digital_file') {
            $hasPrimary = $product->files()
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereIn('scan_status', ['clean', 'structural'])
                ->exists();

            if (! $hasPrimary) {
                $blockers[] = 'فایل اصلی فعال و دارای بررسی ساختاری معتبر وجود ندارد.';
            } elseif (($product->download_policy ?? 'signed') === 'reader_only' && ! $this->hasReadyPrimaryReader($product)) {
                $blockers[] = 'نسخه کامل و آماده خوانشگر برای محصول فقط‌خواندنی موجود نیست.';
            }
        } elseif ($product->type === 'course') {
            $hasPublishableLesson = $product->course()
                ->whereHas('sections.lessons', fn ($query) => $query->publishable())
                ->exists();

            if (! $hasPublishableLesson) {
                $blockers[] = 'دوره هنوز جلسه معتبر و قابل انتشار ندارد.';
            }
        } else {
            $blockers[] = 'نوع محصول پشتیبانی نمی‌شود.';
        }

        return $blockers;
    }

    public function hasReadyPrimaryReader(StoreProduct $product): bool
    {
        $isEligible = static fn ($file): bool => $file->purpose === 'product_download'
            && $file->is_active
            && $file->is_primary
            && in_array($file->scan_status, ['clean', 'structural'], true);

        $file = $product->relationLoaded('files')
            ? $product->files->first($isEligible)
            : $product->files()
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereIn('scan_status', ['clean', 'structural'])
                ->first();

        $document = $file?->document;
        if (! $file || ! $document
            || (int) $document->product_id !== (int) $product->id
            || (int) $document->product_file_id !== (int) $file->id) {
            return false;
        }

        $hasPages = array_key_exists('pages_count', $document->getAttributes())
            ? (int) $document->pages_count > 0
            : ($document->relationLoaded('pages')
                ? $document->pages->isNotEmpty()
                : $document->pages()->exists());

        return filled($file->sha256)
            && hash_equals((string) $file->sha256, (string) $document->source_sha256)
            && in_array($document->processing_status, ['completed', 'extracted'], true)
            && (bool) data_get($document->structure_json, 'reader.ready', false)
            && $hasPages;
    }
}
