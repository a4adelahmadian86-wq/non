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
        StorageVolume::query()->where('is_active', true)->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get()->each(function (StorageVolume $volume) use ($connections, &$checked, &$failed) {
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
        $this->info("Healthy: {$checked}; failed: {$failed}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}