<?php

namespace App\Console\Commands;

use App\Models\StoreProductIngestion;
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

        $this->info("Removed {$removed} expired quarantine file(s).");
        return self::SUCCESS;
    }
}
