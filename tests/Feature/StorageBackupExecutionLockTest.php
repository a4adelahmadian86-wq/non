<?php

namespace Tests\Feature;

use App\Services\Storage\StorageBackupService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class StorageBackupExecutionLockTest extends TestCase
{
    public function test_a_second_backup_cannot_start_while_an_execution_lock_is_held(): void
    {
        $lock = Cache::lock('farast:backup:execution', 21600);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(RuntimeException::class);
            app(StorageBackupService::class)->runWeekly();
        } finally {
            $lock->release();
        }
    }
}
