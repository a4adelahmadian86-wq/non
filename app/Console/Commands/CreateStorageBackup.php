<?php

namespace App\Console\Commands;

use App\Services\StorageBackupService;
use Illuminate\Console\Command;

class CreateStorageBackup extends Command
{
    protected $signature = 'farast:storage-backup';
    protected $description = 'Create and verify an encrypted off-site backup of the database and stored files.';

    public function handle(StorageBackupService $backups): int
    {
        $run = $backups->runWeekly();
        if ($run->status !== 'completed') {
            $this->error($run->error_message ?: 'Backup failed.');
            return self::FAILURE;
        }

        $this->info('Backup #'.$run->id.' completed; '.number_format($run->size_bytes).' bytes stored.');
        return self::SUCCESS;
    }
}