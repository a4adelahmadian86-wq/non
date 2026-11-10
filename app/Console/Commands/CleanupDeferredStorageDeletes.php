<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageManager;
use Illuminate\Console\Command;

class CleanupDeferredStorageDeletes extends Command
{
    protected $signature = 'storage:cleanup-deferred-deletes {--limit=100}';
    protected $description = 'Finalize object deletions deferred until backup snapshots complete';

    public function handle(StorageManager $storage): int
    {
        $count = $storage->cleanupDeferredDeletes(max(1, min(500, (int) $this->option('limit'))));
        $this->info("Finalized {$count} deferred deletion(s).");
        return self::SUCCESS;
    }
}