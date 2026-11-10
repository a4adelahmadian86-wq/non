<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageTransferService;
use Illuminate\Console\Command;

class TransferPendingStorageFiles extends Command
{
    protected $signature = 'storage:transfer-pending {--limit=100}';
    protected $description = 'Transfer staged uploads to their configured storage volumes';

    public function handle(StorageTransferService $transfer): int
    {
        $count = $transfer->transferPending(max(1, min(500, (int) $this->option('limit'))));
        $this->info("Transferred {$count} staged file(s).");
        return self::SUCCESS;
    }
}