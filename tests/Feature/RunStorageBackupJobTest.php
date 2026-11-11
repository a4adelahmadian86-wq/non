<?php

namespace Tests\Feature;

use App\Jobs\RunStorageBackupJob;
use App\Models\StorageBackupRun;
use App\Services\Storage\StorageBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RunStorageBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_destination_failure_is_not_reported_as_a_clean_backup(): void
    {
        $request = StorageBackupRun::create([
            'backup_type' => 'manual',
            'source_name' => 'scheduler_request',
            'status' => 'queued',
            'metadata' => ['request_only' => true],
        ]);

        $backup = Mockery::mock(StorageBackupService::class);
        $backup->shouldReceive('runWeekly')
            ->once()
            ->with('manual')
            ->andReturn([
                ['volume_id' => 1, 'status' => 'completed'],
                ['volume_id' => 2, 'status' => 'failed'],
            ]);

        (new RunStorageBackupJob((int) $request->id))->handle($backup);

        $this->assertDatabaseHas('storage_backup_runs', [
            'id' => $request->id,
            'status' => 'failed',
            'error_message' => 'تکمیل بکاپ در 1 مقصد ناموفق بود.',
        ]);

        $this->assertSame(
            ['completed', 'failed'],
            array_column(
                (array) StorageBackupRun::query()->findOrFail($request->id)->metadata['destinations'],
                'status'
            )
        );
    }
}
