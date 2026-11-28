<?php

namespace App\Console\Commands;

use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use Illuminate\Console\Command;

class CheckStorageVolumes extends Command
{
    protected $signature = 'storage:check-volumes {--limit=100}';
    protected $description = 'Probe storage providers and refresh capacity inventory';

    public function handle(StorageConnectionService $connections): int
    {
        $checked = 0;
        $failed = 0;
        $skipped = 0;
        StorageVolume::query()->where('is_active', true)->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get()->each(function (StorageVolume $volume) use ($connections, &$checked, &$failed, &$skipped) {
            $interval = filter_var(data_get($volume->metadata, 'health_check_interval_minutes', 5), FILTER_VALIDATE_INT);
            $interval = $interval === false ? 5 : max(5, min(1440, $interval));
            if ($volume->status === 'healthy'
                && $volume->last_checked_at
                && $volume->last_checked_at->gt(now()->subMinutes($interval))) {
                $skipped++;
                return;
            }

            try {
                $connections->verify($volume);
                $checked++;
            } catch (\Throwable $exception) {
                $volume->update([
                    'status' => 'error',
                    'last_checked_at' => now(),
                    'metadata' => array_merge((array) $volume->metadata, ['last_error' => mb_substr($exception->getMessage(), 0, 500)]),
                ]);
                $failed++;
                report($exception);
            }
        });
        $this->info("Healthy: {$checked}; failed: {$failed}; skipped: {$skipped}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}