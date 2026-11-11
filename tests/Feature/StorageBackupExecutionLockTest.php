<?php

namespace Tests\Feature;

use App\Services\Storage\StorageBackupService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class StorageBackupExecutionLockTest extends TestCase
{
    public function test_legacy_v1_encrypted_archives_remain_verifiable(): void
    {
        config(['storage_management.backup.encryption_key' => 'legacy-test-backup-key']);
        $archive = tempnam(sys_get_temp_dir(), 'farast-legacy-backup-');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($zip->setPassword('legacy-test-backup-key'));
            $this->assertTrue($zip->addFromString('manifest.json', json_encode([
                'created_at' => now()->toIso8601String(),
                'application' => 'FARAST',
                'database_driver' => 'sqlite',
                'storage_policy' => 'farast-storage-core-v1',
            ], JSON_THROW_ON_ERROR)));
            $this->assertTrue($zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256));
            $this->assertTrue($zip->addFromString('database/database.sql', 'legacy database dump'));
            $this->assertTrue($zip->setEncryptionName('database/database.sql', ZipArchive::EM_AES_256));
            $this->assertTrue($zip->close());

            app(StorageBackupService::class)->verifyArchive($archive);
            $this->assertTrue(true);
        } finally {
            @unlink($archive);
        }
    }

    public function test_a_second_backup_cannot_start_while_an_execution_lock_is_held(): void
    {
        $lock = Cache::lock('farast:backup:execution', 18000);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(RuntimeException::class);
            app(StorageBackupService::class)->runWeekly();
        } finally {
            $lock->release();
        }
    }
}
