<?php

namespace App\Services;

use App\Models\StorageVolume;
use App\Services\Storage\S3CompatibleAdapter;
use Illuminate\Support\Carbon;
use RuntimeException;

class StorageConnectionService
{
    public function __construct(private readonly S3CompatibleAdapter $s3) {}

    public function verify(StorageVolume $volume, bool $refreshInventory = false): array
    {
        $inventory = null;
        $inventoryError = null;
        $lastInventoryAttempt = data_get($volume->metadata, 'inventory_attempted_at');
        $shouldRefreshInventory = $refreshInventory || ! $lastInventoryAttempt
            || Carbon::parse($lastInventoryAttempt)->lt(now()->subHours(6));
        if ($volume->driver === 's3') {
            $result = $this->s3->probe($volume);
            if ($shouldRefreshInventory) {
                try {
                    $inventory = $this->s3->inventory($volume);
                } catch (\Throwable $exception) {
                    $inventoryError = mb_substr($exception->getMessage(), 0, 400);
                }
            }
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
            if ($shouldRefreshInventory) $inventory = $this->localInventory($root);
        } else {
            throw new RuntimeException('درایور این سرویس هنوز پشتیبانی نمی‌شود.');
        }

        $volume->forceFill([
            'status' => 'healthy',
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'capabilities' => array_merge($result, ['list' => $inventory !== null ? true : (bool) data_get($volume->capabilities, 'list', false)]),
            'used_bytes' => $inventory['used_bytes'] ?? $volume->used_bytes,
            'object_count' => $inventory['object_count'] ?? $volume->object_count,
            'metadata' => array_merge((array) $volume->metadata, [
                'inventory_attempted_at' => $shouldRefreshInventory ? now()->toIso8601String() : data_get($volume->metadata, 'inventory_attempted_at'),
                'inventory_checked_at' => $inventory !== null ? now()->toIso8601String() : data_get($volume->metadata, 'inventory_checked_at'),
                'inventory_error' => $inventoryError ?? data_get($volume->metadata, 'inventory_error'),
            ]),
        ])->save();

        return $result;
    }

    private function localInventory(string $root): array
    {
        $count = 0;
        $bytes = 0;
        if (! is_dir($root)) return ['object_count' => 0, 'used_bytes' => 0];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && ! str_starts_with($file->getFilename(), '.farast-health-')) {
                $count++;
                $bytes += $file->getSize();
            }
        }
        return ['object_count' => $count, 'used_bytes' => $bytes];
    }
}