<?php

namespace App\Services;

use App\Models\StorageVolume;
use App\Services\Storage\S3CompatibleAdapter;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

class StorageConnectionService
{
    public function disk(StorageVolume $volume): LaravelFilesystemAdapter
    {
        $credentials = (array) ($volume->credentials ?? []);

        if ($volume->driver === 'local') {
            $adapter = new LocalFilesystemAdapter(storage_path('app/storage-volumes/'.$volume->id));
            return new LaravelFilesystemAdapter(new Filesystem($adapter, ['visibility' => 'private']), $adapter, ['throw' => true]);
        }

        if ($volume->driver !== 's3') {
            throw new RuntimeException('درایور این سرویس پشتیبانی نمی‌شود.');
        }

        $adapter = new S3CompatibleAdapter([
            'key' => $credentials['key'] ?? null,
            'secret' => $credentials['secret'] ?? null,
            'bucket' => $volume->bucket ?: ($credentials['bucket'] ?? null),
            'region' => $volume->region ?: ($credentials['region'] ?? 'us-east-1'),
            'endpoint' => $volume->endpoint ?: ($credentials['endpoint'] ?? null),
            'use_path_style_endpoint' => $credentials['use_path_style_endpoint'] ?? true,
            'timeout' => $credentials['timeout'] ?? 30,
            'connect_timeout' => $credentials['connect_timeout'] ?? 8,
        ]);

        return new LaravelFilesystemAdapter(new Filesystem($adapter, ['visibility' => 'private']), $adapter, ['throw' => true]);
    }

    public function verify(StorageVolume $volume): array
    {
        $disk = $this->disk($volume);
        $probe = '.farast-health/'.bin2hex(random_bytes(16)).'.txt';
        $payload = 'farast-storage-check:'.now()->toIso8601String();
        $disk->put($probe, $payload, ['visibility' => 'private']);

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
            'capabilities' => ['read' => true, 'write' => true, 'delete' => true, 'private' => true],
        ])->save();

        return ['read' => true, 'write' => true, 'delete' => true, 'private' => true];
    }
}