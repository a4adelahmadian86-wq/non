<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FarastObservabilityService
{
    public function snapshot(): array
    {
        $components = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'logging' => $this->logging(),
        ];

        $statuses = array_column($components, 'status');
        $overall = in_array('critical', $statuses, true)
            ? 'critical'
            : (in_array('warning', $statuses, true) || in_array('unknown', $statuses, true) ? 'warning' : 'healthy');

        return [
            'status' => $overall,
            'checked_at' => now()->toIso8601String(),
            'environment' => (string) config('app.env'),
            'observability_enabled' => (bool) config('observability.enabled', true),
            'components' => $components,
        ];
    }

    private function database(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'healthy', 'message' => 'اتصال پایگاه داده برقرار است.'];
        } catch (Throwable $exception) {
            $this->recordProbeFailure('database', $exception);

            return ['status' => 'critical', 'message' => 'اتصال پایگاه داده ناموفق است.'];
        }
    }

    private function queue(): array
    {
        $connection = (string) config('queue.default', 'unknown');
        $queueDatabase = config("queue.connections.{$connection}.connection") ?: config('database.default');
        $queueTable = (string) config("queue.connections.{$connection}.table", 'jobs');
        $failedDatabase = config('queue.failed.database') ?: config('database.default');
        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        $result = [
            'status' => 'healthy',
            'connection' => $connection,
            'queue_database' => $queueDatabase,
            'pending_jobs' => null,
            'failed_jobs' => null,
            'oldest_pending_minutes' => null,
        ];

        try {
            if ($connection === 'sync') {
                $result['status'] = 'warning';
                $result['message'] = 'پردازش صف به‌صورت هم‌زمان انجام می‌شود؛ backlog پایدار قابل اندازه‌گیری نیست.';
            } elseif (Schema::connection($queueDatabase)->hasTable($queueTable)) {
                $jobs = DB::connection($queueDatabase)->table($queueTable);
                $result['pending_jobs'] = (int) (clone $jobs)->count();
                $oldest = (clone $jobs)->min('created_at');
                $result['oldest_pending_minutes'] = $oldest
                    ? max(0, now()->timestamp - (int) $oldest) / 60
                    : 0;
            } else {
                $result['status'] = 'warning';
                $result['message'] = 'جدول صف در اتصال پایگاه دادهٔ پیکربندی‌شده وجود ندارد.';
            }

            if (Schema::connection($failedDatabase)->hasTable($failedTable)) {
                $result['failed_jobs'] = (int) DB::connection($failedDatabase)->table($failedTable)->count();
                if ($result['failed_jobs'] > 0) {
                    $result['status'] = 'warning';
                }
            } else {
                $result['status'] = 'warning';
                $result['failed_jobs_warning'] = 'جدول failed_jobs وجود ندارد.';
            }
        } catch (Throwable $exception) {
            $this->recordProbeFailure('queue', $exception);
            $result['status'] = 'critical';
            $result['message'] = 'خواندن وضعیت صف ناموفق است.';
        }

        return $result;
    }

    private function storage(): array
    {
        $disk = (string) config('filesystems.default', 'local');
        $privateRoot = (string) config('filesystems.disks.private.root', '');
        $result = [
            'status' => 'unknown',
            'default_disk' => $disk,
            'private_disk_configured' => $privateRoot !== '',
            'private_root_writable' => null,
            'volumes' => null,
            'pending_transfers' => null,
        ];

        try {
            if ($privateRoot !== '') {
                $result['private_root_writable'] = is_dir($privateRoot) && is_writable($privateRoot);
                $result['status'] = $result['private_root_writable'] ? 'healthy' : 'critical';
            }

            if (Schema::hasTable('storage_volumes')) {
                $query = DB::table('storage_volumes');
                if (Schema::hasColumn('storage_volumes', 'is_active')) {
                    $query->where('is_active', true);
                }
                $result['volumes'] = [
                    'total' => (int) (clone $query)->count(),
                    'healthy' => (int) (clone $query)->where('status', 'healthy')->count(),
                    'unhealthy_or_unverified' => (int) (clone $query)->whereNotIn('status', ['healthy'])->count(),
                ];
                if ($result['volumes']['unhealthy_or_unverified'] > 0 && $result['status'] === 'healthy') {
                    $result['status'] = 'warning';
                }
            } else {
                $result['status'] = 'warning';
                $result['inventory_warning'] = 'جدول موجودی Volumeها نصب نشده است.';
            }

            if (Schema::hasTable('storage_managed_files')) {
                $result['pending_transfers'] = (int) DB::table('storage_managed_files')
                    ->whereIn('status', ['pending_transfer', 'transfer_error'])
                    ->count();
                if ($result['pending_transfers'] > 0 && $result['status'] === 'healthy') {
                    $result['status'] = 'warning';
                }
            } else {
                $result['status'] = 'warning';
                $result['transfer_warning'] = 'جدول مدیریت فایل‌ها نصب نشده است.';
            }

            if ($result['status'] === 'unknown') {
                $result['status'] = 'warning';
            }
        } catch (Throwable $exception) {
            $this->recordProbeFailure('storage', $exception);
            $result['status'] = 'critical';
        }

        return $result;
    }


    private function recordProbeFailure(string $component, Throwable $exception): void
    {
        try {
            // Exception messages can contain connection details or provider paths.
            // Keep operational logs useful without copying those values.
            Log::warning('farast.health.probe_failed', [
                'component' => $component,
                'exception_class' => get_class($exception),
            ]);
        } catch (Throwable $loggingException) {
            error_log('FARAST health probe logging failed: '.get_class($loggingException));
        }
    }

    private function logging(): array
    {
        $channel = (string) config('logging.default', 'stack');
        $days = config('logging.channels.daily.days');
        $path = config('logging.channels.daily.path');
        $directory = is_string($path) && $path !== '' ? dirname($path) : null;
        $writable = $directory !== null && is_dir($directory) && is_writable($directory);

        return [
            'status' => $writable ? 'healthy' : 'warning',
            'default_channel' => $channel,
            'daily_retention_days' => is_numeric($days) ? (int) $days : null,
            'log_directory_writable' => $writable,
        ];
    }
}
