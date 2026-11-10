<?php

namespace App\Console\Commands;

use App\Services\UserFileRetentionService;
use Illuminate\Console\Command;

class PruneUserFiles extends Command
{
    protected $signature = 'farast:prune-user-files {--limit=100}';
    protected $description = 'Migrate legacy local user files without deleting retained user content';

    public function handle(UserFileRetentionService $retention): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $migrated = $retention->archiveExpiredLocalFiles($limit);
        $deleted = $retention->deleteExpiredRemoteFiles($limit);
        $this->info("Migrated to managed storage: {$migrated}; automatically deleted: {$deleted}");
        return self::SUCCESS;
    }
}
