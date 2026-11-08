<?php

namespace App\Services;

use App\Models\StoreProduct;

class ProductFileStorageSelector
{
    public function select(StoreProduct $product, array $inspection): string
    {
        $kind = (string) ($inspection['kind'] ?? 'unknown');
        $candidate = (string) config(
            'farast.product_storage.by_kind.'.$kind,
            config('farast.product_storage.default', 'private')
        );

        if ($candidate === 'public' || ! is_array(config('filesystems.disks.'.$candidate))) {
            return 'private';
        }

        return $candidate;
    }
}
