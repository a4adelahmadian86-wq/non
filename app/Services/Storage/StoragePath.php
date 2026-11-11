<?php

namespace App\\Services\\Storage;

use InvalidArgumentException;

final class StoragePath
{
    public static function normalizeObjectKey(string $key): string
    {
        if ($key === '' || strlen($key) > 1024 || str_starts_with($key, '/') || str_contains($key, "\\0") || str_contains($key, '\\\\')) {
            throw new InvalidArgumentException('کلید فایل در فضای ذخیره‌سازی معتبر نیست.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('مسیر فایل خارج از محدوده مجاز فضای ذخیره‌سازی است.');
            }
        }

        return $key;
    }
}
