<?php

namespace App\Console\Commands;

use App\Models\StoreProductIngestion;
use App\Models\StoreProductIntakeSession;
use App\Models\StoreProductIntakeFile;
use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneProductIngestions extends Command
{
    protected $signature = 'farast:prune-product-ingestions {--limit=200}';
    protected $description = 'Delete expired unconfirmed product files from the private quarantine area.';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $removed = 0;

        StoreProductIngestion::query()
            ->where('status', 'inspected')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (StoreProductIngestion $ingestion) use (&$removed) {
                Storage::disk($ingestion->staging_disk ?: 'private')->delete($ingestion->staging_path);
                $ingestion->update(['status' => 'expired', 'staging_path' => 'expired']);
                $removed++;
            });

        $expiredSessions = StoreProductIntakeSession::query()
            ->whereNull('product_id')
            ->whereNotIn('status', [StoreProductIntakeSession::STATUS_SUBMITTED, StoreProductIntakeSession::STATUS_EXPIRED])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $storage = app(StorageManager::class);
        $expiredFiles = 0;
        foreach ($expiredSessions as $session) {
            $session->files()->get()->each(function (StoreProductIntakeFile $file) use (&$expiredFiles, $storage) {
                if (filled($file->staging_path)) {
                    Storage::disk($file->staging_disk ?: 'private')->delete($file->staging_path);
                }
                if (filled($file->managed_disk) && filled($file->managed_path) && $file->managed_file_id) {
                    $storage->delete($file->managed_disk, $file->managed_path);
                }
                $file->update([
                    'status' => 'expired',
                    'staging_path' => null,
                    'managed_disk' => null,
                    'managed_path' => null,
                    'managed_file_id' => null,
                ]);
                $expiredFiles++;
            });
            $session->update(['status' => StoreProductIntakeSession::STATUS_EXPIRED]);
        }

        $this->info("Removed {$removed} expired quarantine file(s) and expired {$expiredFiles} intake asset(s).");
        return self::SUCCESS;
    }
}
