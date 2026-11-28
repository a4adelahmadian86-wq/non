<?php

namespace App\Console\Commands;

use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckStorageVolumes extends Command
{
    protected $signature = 'storage:check-volumes {--limit=100}';
    protected $description = 'Probe storage providers without refreshing full S3 inventory';

    public function handle(StorageConnectionService $connections): int
    {
        $checked = 0;
        $failed = 0;
        $skipped = 0;

        StorageVolume::query()->where('is_active', true)->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get()
            ->each(function (StorageVolume $volume) use ($connections, &$checked, &$failed, &$skipped): void {
                $interval = filter_var(data_get($volume->metadata, 'health_check_interval_minutes', 5), FILTER_VALIDATE_INT);
                $interval = $interval === false ? 5 : max(5, min(1440, $interval));
                $nextCheckAt = data_get($volume->metadata, 'health_check_next_at');
                $retryNotBefore = null;
                if (is_string($nextCheckAt) && $nextCheckAt !== '') {
                    try {
                        $retryNotBefore = Carbon::parse($nextCheckAt);
                    } catch (\\Throwable) {
                        // Ignore malformed retry metadata and perform a fresh health check.
                    }
                }

                if ($retryNotBefore && now()->lt($retryNotBefore)) {
                    $skipped++;
                    return;
                }

                if ($volume->status === 'healthy'
                    && $volume->last_checked_at
                    && $volume->last_checked_at->gt(now()->subMinutes($interval))) {
                    $skipped++;
                    return;
                }

                try {
                    // Scheduled checks must not enumerate a whole bucket. Inventory
                    // refresh is reserved for an explicit admin verification action.
                    $connections->verify($volume, false, true);
                    $metadata = (array) $volume->fresh()->metadata;
                    unset($metadata['health_check_failures'], $metadata['health_check_next_at'], $metadata['last_error']);
                    $volume->update(['metadata' => $metadata]);
                    $checked++;
                } catch (\Throwable $exception) {
                    $failureCount = max(0, (int) data_get($volume->metadata, 'health_check_failures', 0)) + 1;
                    $baseDelay = $interval;
                    $backoffMinutes = min(1440, $baseDelay * (2 ** min(5, $failureCount - 1)));
                    $metadata = array_merge((array) $volume->metadata, [
                        'last_error' => mb_substr($exception->getMessage(), 0, 500),
                        'health_check_failures' => $failureCount,
                        'health_check_next_at' => now()->addMinutes($backoffMinutes)->toIso8601String(),
                    ]);
                    $volume->update([
                        'status' => 'error',
                        'last_checked_at' => now(),
                        'metadata' => $metadata,
                    ]);
                    $failed++;
                    report($exception);
                }
            });

        $this->info("Healthy: {$checked}; failed: {$failed}; skipped: {$skipped}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
