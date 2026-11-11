<?php

namespace Tests\Unit;

use App\Services\DashboardStorageSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardStorageSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_warns_when_active_storage_has_no_successful_backup(): void
    {
        DB::table('storage_volumes')->insert([
            'drive_key' => 'iran_primary',
            'name' => 'Healthy but unbacked primary',
            'driver' => 'local',
            'status' => 'healthy',
            'is_active' => true,
            'used_bytes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = app(DashboardStorageSummaryService::class)->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertSame('attention', $snapshot['status']);
        $this->assertTrue($snapshot['backup_stale']);
        $this->assertNull($snapshot['last_successful_backup_at']);
    }

    public function test_snapshot_does_not_call_an_empty_storage_installation_healthy(): void
    {
        $snapshot = app(DashboardStorageSummaryService::class)->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertSame('unconfigured', $snapshot['status']);
        $this->assertSame(0, $snapshot['active_volumes']);
        $this->assertSame(0, $snapshot['healthy_volumes']);
    }

    public function test_snapshot_reports_real_volume_transfer_and_backup_health(): void
    {
        $healthyVolume = DB::table('storage_volumes')->insertGetId([
            'drive_key' => 'iran_primary',
            'name' => 'Healthy primary',
            'driver' => 'local',
            'status' => 'healthy',
            'is_active' => true,
            'used_bytes' => 100,
            'object_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('storage_volumes')->insert([
            'drive_key' => 'foreign_primary',
            'name' => 'Degraded secondary',
            'driver' => 'local',
            'status' => 'error',
            'is_active' => true,
            'used_bytes' => 0,
            'object_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('storage_managed_files')->insert([
            'storage_volume_id' => $healthyVolume,
            'drive_key' => 'iran_primary',
            'file_key' => 'dashboard/transfer-error.bin',
            'original_name' => 'transfer-error.bin',
            'size_bytes' => 12,
            'classification' => 'critical',
            'source_module' => 'dashboard-test',
            'visibility' => 'private',
            'status' => 'transfer_error',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('storage_backup_runs')->insert([
            'storage_volume_id' => $healthyVolume,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'failed',
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        DB::table('storage_backup_runs')->insert([
            'storage_volume_id' => $healthyVolume,
            'backup_type' => 'weekly',
            'source_name' => 'application',
            'status' => 'completed',
            'completed_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        DB::table('storage_backup_runs')->insert([
            'backup_type' => 'weekly',
            'source_name' => 'snapshot_lock',
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = app(DashboardStorageSummaryService::class)->snapshot();

        $this->assertTrue($snapshot['available']);
        $this->assertSame('attention', $snapshot['status']);
        $this->assertSame(2, $snapshot['active_volumes']);
        $this->assertSame(1, $snapshot['healthy_volumes']);
        $this->assertSame(1, $snapshot['degraded_volumes']);
        $this->assertSame(1, $snapshot['pending_transfers']);
        $this->assertSame(1, $snapshot['transfer_errors']);
        $this->assertSame(1, $snapshot['failed_backups_24h']);
        $this->assertNotNull($snapshot['last_successful_backup_at']);
        $this->assertFalse($snapshot['backup_stale']);
    }

}
