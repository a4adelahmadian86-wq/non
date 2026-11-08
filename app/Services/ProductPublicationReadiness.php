<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductPublicationReadiness
{
    public function hasReadyPrimaryReader(StoreProduct $product): bool
    {
        $file = $product->files()
            ->where('purpose', 'product_download')
            ->where('is_active', true)
            ->where('is_primary', true)
            ->where('scan_status', 'clean')
            ->first();

        $document = $file?->document;
        if (! $file || ! $document) {
            return false;
        }

        return filled($file->sha256)
            && hash_equals((string) $file->sha256, (string) $document->source_sha256)
            && in_array($document->processing_status, ['completed', 'extracted'], true)
            && (bool) data_get($document->structure_json, 'reader.ready', false)
            && $document->pages()->exists();
    }
}
