<?php

namespace App\Services;

use App\Models\StorageVolume;
use Throwable;

class StorageHealthService
{
    public function __construct(private readonly StorageConnectionService $connections)
    {
    }

    public function check(int $limit = 100): array
    {
        $checked = 0;
        $healthy = 0;
        $failed = 0;

        StorageVolume::query()
            ->where('is_active', true)
            ->whereIn('status', ['healthy', 'error', 'degraded', 'unverified'])
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->each(function (StorageVolume $volume) use (&$checked, &$healthy, &$failed): void {
                $checked++;
                try {
                    $this->connections->verify($volume);
                    $healthy++;
                } catch (Throwable $exception) {
                    $metadata = (array) ($volume->metadata ?? []);
                    $metadata['last_health_error'] = mb_substr($exception->getMessage(), 0, 500);
                    $volume->forceFill([
                        'status' => 'error',
                        'last_checked_at' => now(),
                        'metadata' => $metadata,
                    ])->save();
                    $failed++;
                    report($exception);
                }
            });

        return compact('checked', 'healthy', 'failed');
    }
}
