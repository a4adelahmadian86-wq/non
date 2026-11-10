<?php

namespace App\Services;

use App\Models\StorageVolume;
use App\Services\Storage\S3CompatibleAdapter;
use RuntimeException;

class StorageConnectionService
{
    public function __construct(private readonly S3CompatibleAdapter $s3) {}

    public function verify(StorageVolume $volume): array
    {
        if ($volume->driver === 's3') {
            $result = $this->s3->probe($volume);
        } elseif ($volume->driver === 'local') {
            $root = storage_path('app/storage-volumes/'.$volume->id);
            if (! is_dir($root) && ! mkdir($root, 0775, true) && ! is_dir($root)) {
                throw new RuntimeException('ایجاد مسیر فضای ذخیره‌سازی محلی ممکن نشد.');
            }
            $probe = $root.'/.farast-health-'.bin2hex(random_bytes(12)).'.txt';
            $payload = 'farast-storage-probe:'.now()->toIso8601String();
            if (file_put_contents($probe, $payload, LOCK_EX) === false) throw new RuntimeException('نوشتن فایل آزمایشی ناموفق بود.');
            try {
                if (file_get_contents($probe) !== $payload) throw new RuntimeException('آزمون خواندن فایل ناموفق بود.');
            } finally {
                @unlink($probe);
            }
            $result = ['read' => true, 'write' => true, 'delete' => true];
        } else {
            throw new RuntimeException('درایور این سرویس هنوز پشتیبانی نمی‌شود.');
        }

        $volume->forceFill([
            'status' => 'healthy',
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'capabilities' => $result,
        ])->save();

        return $result;
    }
}