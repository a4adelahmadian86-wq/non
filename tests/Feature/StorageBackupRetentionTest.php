<?php

namespace Tests\Feature;

use App\Models\StorageBackupRun;
use App\Models\StorageVolume;
use App\Services\Storage\StorageBackupService;
use App\Services\Storage\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StorageBackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_retention_prunes_oldest_verified_backups_but_keeps_manual_snapshots(): void
    {
        config(['storage_management.backup.weekly_retention' => 2]);

        $volume = StorageVolume::create([
            'drive_key' => 'foreign_backup',
            'name' => 'Encrypted backup test volume',
            'provider' => 's3-compatible',
            'driver' => 's3',
            'region' => 'test',
            'endpoint' => 'https://storage.example.test',
            'bucket' => 'farast-backups',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'capacity_bytes' => 1000,
            'used_bytes' => 500,
            'object_count' => 5,
            'priority' => 10,
            'is_active' => true,
            'status' => 'healthy',
        ]);

        $weeklyRuns = collect();
        for ($age = 0; $age < 4; $age++) {
            $weeklyRuns->push(StorageBackupRun::create([
                'storage_volume_id' => $volume->id,
                'backup_type' => 'weekly',
                'source_name' => 'application',
                'status' => 'completed',
                'object_key' => "weekly/backup-{$age}.zip",
                'size_bytes' => 100,
                'checksum' => str_repeat('a', 64),
                'started_at' => now()->subDays($age),
                'completed_at' => now()->subDays($age),
                'metadata' => ['encrypted' => true],
            ]));
        }

        $manual = StorageBackupRun::create([
            'storage_volume_id' => $volume->id,
            'backup_type' => 'manual',
            'source_name' => 'application',
            'status' => 'completed',
            'object_key' => 'manual/before-release.zip',
            'size_bytes' => 100,
            'checksum' => str_repeat('b', 64),
            'started_at' => now()->subDays(30),
            'completed_at' => now()->subDays(30),
            'metadata' => ['encrypted' => true],
        ]);

        $storage = Mockery::mock(StorageManager::class);
        $storage->shouldReceive('volumeDisk')
            ->with(Mockery::on(fn ($candidate) => $candidate->id === $volume->id))
            ->twice()
            ->andReturn('storage_volume:'.$volume->id);
        $storage->shouldReceive('delete')
            ->with('storage_volume:'.$volume->id, Mockery::on(fn ($key) => in_array($key, [
                'weekly/backup-2.zip',
                'weekly/backup-3.zip',
            ], true)))
            ->twice()
            ->andReturnNull();

        $service = new StorageBackupService($storage);
        $pruned = $service->pruneWeeklyBackupsForVolume($volume);

        $this->assertSame(2, $pruned);
        $this->assertSame(['completed', 'completed', 'pruned', 'pruned'], $weeklyRuns
            ->sortByDesc('completed_at')
            ->map(fn (StorageBackupRun $run) => $run->fresh()->status)
            ->values()
            ->all());
        $this->assertSame('completed', $manual->fresh()->status);

        $volume->refresh();
        $this->assertSame(300, (int) $volume->used_bytes);
        $this->assertSame(3, (int) $volume->object_count);
    }
}
