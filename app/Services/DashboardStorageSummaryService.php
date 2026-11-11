<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardStorageSummaryService
{
    /**
     * Read persisted storage inventory and backup outcomes without probing
     * remote providers on every dashboard request.
     *
     * @return array{available: bool, status: string, active_volumes: int|null, healthy_volumes: int|null, degraded_volumes: int|null, pending_transfers: int|null, transfer_errors: int|null, failed_backups_24h: int|null, last_successful_backup_at: string|null}
     */
    public function snapshot(): array
    {
        $unavailable = [
            'available' => false,
            'status' => 'unavailable',
            'active_volumes' => null,
            'healthy_volumes' => null,
            'degraded_volumes' => null,
            'pending_transfers' => null,
            'transfer_errors' => null,
            'failed_backups_24h' => null,
            'last_successful_backup_at' => null,
        ];

        try {
            $required = [
                'storage_volumes' => ['id', 'is_active', 'status'],
                'storage_managed_files' => ['id', 'status'],
                'storage_backup_runs' => ['id', 'status', 'source_name', 'created_at', 'completed_at'],
            ];

            foreach ($required as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    return $unavailable;
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        return $unavailable;
                    }
                }
            }

            $volumes = DB::table('storage_volumes')->where('is_active', true);
            $volumeCounts = (clone $volumes)
                ->selectRaw('COUNT(*) as active_count')
                ->selectRaw("SUM(CASE WHEN status = 'healthy' THEN 1 ELSE 0 END) as healthy_count")
                ->first();

            $pendingTransfers = (int) DB::table('storage_managed_files')
                ->whereIn('status', ['pending_transfer', 'transfer_error', 'delete_pending'])
                ->count();
            $transferErrors = (int) DB::table('storage_managed_files')
                ->where('status', 'transfer_error')
                ->count();

            $backupRuns = DB::table('storage_backup_runs')
                ->where('source_name', '!=', 'snapshot_lock');
            $failedBackups24h = (int) (clone $backupRuns)
                ->where('status', 'failed')
                ->where(function ($recent) {
                    $recent->where('completed_at', '>=', now()->subDay())
                        ->orWhere(function ($pending) {
                            $pending->whereNull('completed_at')
                                ->where('created_at', '>=', now()->subDay());
                        });
                })
                ->count();
            $lastSuccessfulBackup = (clone $backupRuns)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->max('completed_at');

            $active = (int) ($volumeCounts->active_count ?? 0);
            $healthy = (int) ($volumeCounts->healthy_count ?? 0);
            $degraded = max(0, $active - $healthy);
            $backupStale = $active > 0 && ($lastSuccessfulBackup === null || Carbon::parse($lastSuccessfulBackup)->lt(now()->subDays(7)));
            $attention = $degraded > 0 || $transferErrors > 0 || $failedBackups24h > 0 || $backupStale;

            return [
                'available' => true,
                'status' => $active === 0 ? 'unconfigured' : ($attention ? 'attention' : 'healthy'),
                'active_volumes' => $active,
                'healthy_volumes' => $healthy,
                'degraded_volumes' => $degraded,
                'pending_transfers' => $pendingTransfers,
                'transfer_errors' => $transferErrors,
                'failed_backups_24h' => $failedBackups24h,
                'backup_stale' => $backupStale,
                'last_successful_backup_at' => $lastSuccessfulBackup ? (string) $lastSuccessfulBackup : null,
            ];
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
