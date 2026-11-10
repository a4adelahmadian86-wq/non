<?php

namespace App\\Services;

use App\\Models\\StorageVolume;
use Illuminate\\Support\\Facades\\Storage;
use RuntimeException;

class StorageConnectionService
{
    public function disk(StorageVolume $volume)
    {
        $credentials = $volume->credentials ?? [];

        if ($volume->driver === 'local') {
            return Storage::build([
                'driver' => 'local',
                'root' => storage_path('app/storage-volumes/'.$volume->id),
                'throw' => true,
            ]);
        }

        if ($volume->driver !== 's3') {
            throw new RuntimeException('درایور این سرویس هنوز نصب یا پیکربندی نشده است.');
        }

        foreach (['key', 'secret', 'bucket'] as $required) {
            if (empty($credentials[$required]) && empty($volume->bucket) && $required === 'bucket') {
                throw new RuntimeException('نام Bucket در اطلاعات اتصال موجود نیست.');
            }
            if ($required !== 'bucket' && empty($credentials[$required])) {
                throw new RuntimeException('اطلاعات دسترسی سرویس ناقص است.');
            }
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $credentials['key'],
            'secret' => $credentials['secret'],
            'region' => $volume->region ?: ($credentials['region'] ?? 'us-east-1'),
            'bucket' => $volume->bucket ?: ($credentials['bucket'] ?? null),
            'endpoint' => $volume->endpoint ?: ($credentials['endpoint'] ?? null),
            'use_path_style_endpoint' => (bool) ($credentials['use_path_style_endpoint'] ?? true),
            'throw' => true,
        ]);
    }

    public function verify(StorageVolume $volume): array
    {
        $disk = $this->disk($volume);
        $probe = '.farast-health/'.bin2hex(random_bytes(12)).'.txt';
        $payload = 'farast-storage-check:'.now()->toIso8601String();
        $disk->put($probe, $payload);
        try {
            if ($disk->get($probe) !== $payload) {
                throw new RuntimeException('آزمون خواندن فایل با محتوای مورد انتظار مطابقت نداشت.');
            }
        } finally {
            $disk->delete($probe);
        }

        $volume->forceFill([
            'status' => 'healthy',
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'capabilities' => ['read' => true, 'write' => true, 'delete' => true],
        ])->save();

        return ['read' => true, 'write' => true, 'delete' => true];
    }
}