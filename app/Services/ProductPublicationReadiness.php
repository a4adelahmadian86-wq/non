<?php

namespace App\\Services;

use App\\Models\\StoreProduct;

class ProductPublicationReadiness
{
    public function hasReadyPrimaryReader(StoreProduct $product): bool
    {
        $isEligible = static fn ($file): bool => $file->purpose === 'product_download'
            && $file->is_active
            && $file->is_primary
            && $file->scan_status === 'clean';

        $file = $product->relationLoaded('files')
            ? $product->files->first($isEligible)
            : $product->files()
                ->where('purpose', 'product_download')
                ->where('is_active', true)
                ->where('is_primary', true)
                ->where('scan_status', 'clean')
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
