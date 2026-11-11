<?php

namespace App\Services\Storage;

use App\Models\StorageVolume;
use InvalidArgumentException;

final class StoragePath
{
    public static function normalizeObjectKey(string $key): string
    {
        if ($key === '' || strlen($key) > 1024 || str_starts_with($key, '/') || str_contains($key, "\0") || str_contains($key, '\\')) {
            throw new InvalidArgumentException('کلید فایل در فضای ذخیره‌سازی معتبر نیست.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('مسیر فایل خارج از محدوده مجاز فضای ذخیره‌سازی است.');
            }
        }

        return $key;
    }

    public static function localVolumePath(StorageVolume $volume, string $key): string
    {
        $key = self::normalizeObjectKey($key);
        $root = storage_path('app/storage-volumes/'.$volume->id);
        if (is_link($root)) {
            throw new InvalidArgumentException('مسیر ریشه فضای محلی نباید پیوند نمادین باشد.');
        }

        $cursor = $root;
        foreach (explode('/', $key) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('مسیر فایل از پیوند نمادین عبور می‌کند و مجاز نیست.');
            }
        }

        return $cursor;
    }
}
