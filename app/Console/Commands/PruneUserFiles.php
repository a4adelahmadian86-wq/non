<?php

namespace App\Console\Commands;

use App\Services\UserFileRetentionService;
use Illuminate\Console\Command;

class PruneUserFiles extends Command
{
    protected $signature = 'farast:prune-user-files {--limit=100}';
    protected $description = 'Archive expired local user files and remove expired remote archives';

    public function handle(UserFileRetentionService $retention): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $archived = $retention->archiveExpiredLocalFiles($limit);
        $deleted = $retention->deleteExpiredRemoteFiles($limit);
        $this->info("Archived: {$archived}; deleted: {$deleted}");
        return self::SUCCESS;
    }
}
