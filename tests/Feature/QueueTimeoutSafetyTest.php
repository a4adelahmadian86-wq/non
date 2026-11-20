<?php

namespace Tests\Feature;

use App\Jobs\GenerateProductDocumentAudio;
use App\Jobs\ProcessProductDocument;
use App\Jobs\RunStorageBackupJob;
use Tests\TestCase;

class QueueTimeoutSafetyTest extends TestCase
{
    public function test_queue_reservation_windows_outlive_heavy_job_timeouts(): void
    {
        $documentJob = new ProcessProductDocument(1);
        $audioJob = new GenerateProductDocumentAudio(1);
        $backupJob = new RunStorageBackupJob(1);

        $this->assertSame('documents', $documentJob->queue);
        $this->assertSame('default', $audioJob->queue);
        $this->assertSame('backups', $backupJob->connection);
        $this->assertSame('backups', $backupJob->queue);

        $this->assertGreaterThan(
            $documentJob->timeout,
            (int) config('queue.connections.database.retry_after'),
            'Document job reservation must outlive its worker timeout.',
        );
        $this->assertGreaterThan(
            $audioJob->timeout,
            (int) config('queue.connections.database.retry_after'),
            'Audio job reservation must outlive its worker timeout.',
        );
        $this->assertGreaterThan(
            $backupJob->timeout,
            (int) config('queue.connections.backups.retry_after'),
            'Backup job reservation must outlive its worker timeout.',
        );

        $this->assertTrue((bool) config('queue.connections.database.after_commit'));
        $this->assertTrue((bool) config('queue.connections.backups.after_commit'));
    }
}
