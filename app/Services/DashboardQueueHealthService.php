<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardQueueHealthService
{
    /**
     * Return only queue facts that can be measured for the configured driver.
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $snapshot = [
            'driver' => $driver,
            'status' => 'unobservable',
            'queued' => null,
            'ready' => null,
            'reserved' => null,
            'failed' => null,
            'failed_24h' => null,
            'oldest_ready_seconds' => null,
            'checked_at' => now()->toISOString(),
        ];

        if ($driver === 'sync') {
            $failedCount = $this->failedCount();

            return array_replace($snapshot, [
                'status' => $failedCount !== null && $failedCount > 0 ? 'attention' : 'immediate',
                'queued' => 0,
                'ready' => 0,
                'reserved' => 0,
                'failed' => $failedCount,
            ]);
        }

        if ($driver !== 'database') {
            return $snapshot;
        }

        try {
            $connection = config('queue.connections.database.connection') ?: config('database.default');
            $failedConnection = config('queue.failed.database') ?: config('database.default');
            $jobsTable = (string) config('queue.connections.database.table', 'jobs');
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            $schema = Schema::connection($connection);

            if (! $schema->hasTable($jobsTable)
                || ! $schema->hasColumn($jobsTable, 'reserved_at')
                || ! $schema->hasColumn($jobsTable, 'available_at')
                || ! $schema->hasColumn($jobsTable, 'created_at')) {
                return array_replace($snapshot, ['status' => 'misconfigured']);
            }

            $jobs = DB::connection($connection)->table($jobsTable);
            $nowTimestamp = now()->timestamp;
            $queued = (int) (clone $jobs)->count();
            $readyQuery = (clone $jobs)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $nowTimestamp);
            $ready = (int) (clone $readyQuery)->count();
            $reserved = (int) (clone $jobs)->whereNotNull('reserved_at')->count();
            $oldestAvailableAt = (clone $readyQuery)->min('available_at');
            $failed = null;
            $failed24h = null;
            $failedTableAvailable = Schema::connection($failedConnection)->hasTable($failedTable);

            if ($failedTableAvailable) {
                $failedJobs = DB::connection($failedConnection)->table($failedTable);
                $failed = (int) (clone $failedJobs)->count();
                if (Schema::connection($failedConnection)->hasColumn($failedTable, 'failed_at')) {
                    $failed24h = (int) (clone $failedJobs)
                        ->where('failed_at', '>=', now()->subDay())
                        ->count();
                }
            }

            return array_replace($snapshot, [
                'status' => ! $failedTableAvailable
                    ? 'misconfigured'
                    : (($failed ?? 0) > 0 ? 'attention' : ($queued > 0 ? 'queued' : 'clear')),
                'queued' => $queued,
                'ready' => $ready,
                'reserved' => $reserved,
                'failed' => $failed,
                'failed_24h' => $failed24h,
                'oldest_ready_seconds' => $oldestAvailableAt === null
                    ? 0
                    : max(0, $nowTimestamp - (int) $oldestAvailableAt),
            ]);
        } catch (Throwable) {
            return array_replace($snapshot, ['status' => 'unavailable']);
        }
    }

    private function failedCount(): ?int
    {
        try {
            $connection = config('queue.failed.database') ?: config('database.default');
            $table = (string) config('queue.failed.table', 'failed_jobs');

            return Schema::connection($connection)->hasTable($table)
                ? (int) DB::connection($connection)->table($table)->count()
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
