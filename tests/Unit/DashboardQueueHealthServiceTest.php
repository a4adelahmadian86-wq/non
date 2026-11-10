<?php

namespace Tests\Unit;

use App\Services\DashboardQueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardQueueHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_snapshot_reports_ready_reserved_and_failed_jobs(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
            'queue.failed.database' => null,
            'queue.failed.table' => 'failed_jobs',
        ]);

        $now = now()->timestamp;
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now - 120, 'created_at' => $now - 180],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => $now - 30, 'available_at' => $now - 60, 'created_at' => $now - 90],
            ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now + 600, 'created_at' => $now - 10],
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'dashboard-queue-failure',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test failure',
            'failed_at' => now()->subHour(),
        ]);

        $snapshot = app(DashboardQueueHealthService::class)->snapshot();

        $this->assertSame('attention', $snapshot['status']);
        $this->assertSame(3, $snapshot['queued']);
        $this->assertSame(1, $snapshot['ready']);
        $this->assertSame(1, $snapshot['reserved']);
        $this->assertSame(1, $snapshot['failed']);
        $this->assertSame(1, $snapshot['failed_24h']);
        $this->assertGreaterThanOrEqual(180, $snapshot['oldest_ready_seconds']);
    }

    public function test_database_queue_reports_missing_failed_jobs_table_as_misconfigured(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
            'queue.failed.database' => null,
            'queue.failed.table' => 'failed_jobs',
        ]);
        Schema::dropIfExists('failed_jobs');

        $snapshot = app(DashboardQueueHealthService::class)->snapshot();

        $this->assertSame('misconfigured', $snapshot['status']);
        $this->assertNull($snapshot['failed']);
        $this->assertSame(0, $snapshot['queued']);
    }

    public function test_sync_queue_is_reported_as_immediate_without_inventing_backlog(): void
    {
        config(['queue.default' => 'sync']);

        $snapshot = app(DashboardQueueHealthService::class)->snapshot();

        $this->assertSame('immediate', $snapshot['status']);
        $this->assertSame(0, $snapshot['queued']);
        $this->assertSame(0, $snapshot['ready']);
        $this->assertSame(0, $snapshot['reserved']);
    }

    public function test_unobservable_queue_driver_does_not_report_zero_backlog(): void
    {
        config(['queue.default' => 'sqs']);

        $snapshot = app(DashboardQueueHealthService::class)->snapshot();

        $this->assertSame('unobservable', $snapshot['status']);
        $this->assertNull($snapshot['queued']);
        $this->assertNull($snapshot['failed']);
    }
}
