<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditEventService;
use App\Services\FarastObservabilityService;
use App\Services\FarastTraceContext;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CheckObservability extends Command
{
    protected $signature = 'farast:observability:check';

    protected $description = 'Evaluate operational health and emit deduplicated incident/recovery alerts';

    public function handle(
        FarastObservabilityService $observability,
        NotificationService $notifications,
        AuditEventService $auditEvents,
        FarastTraceContext $trace
    ): int {
        $correlationId = (string) Str::uuid();
        $trace->set(null, $correlationId);
        Log::withContext(['correlation_id' => $correlationId, 'operation' => 'farast:observability:check']);

        try {
            return $this->run($observability, $notifications, $auditEvents);
        } finally {
            Log::withoutContext();
            $trace->clear();
        }
    }

    private function run(
        FarastObservabilityService $observability,
        NotificationService $notifications,
        AuditEventService $auditEvents
    ): int {
        try {
            $snapshot = $observability->snapshot();
        } catch (Throwable $exception) {
            Log::critical('farast.observability.snapshot_failed', [
                'exception_class' => get_class($exception),
            ]);
            $this->error('Health snapshot failed; inspect protected application logs.');

            return self::FAILURE;
        }

        $status = (string) ($snapshot['status'] ?? 'unknown');
        $previous = null;
        try {
            $previous = Cache::get('farast:observability:last-status');
            Cache::put('farast:observability:last-status', $status, now()->addDays(30));
        } catch (Throwable $exception) {
            Log::warning('farast.observability.alert_state_unavailable', [
                'exception_class' => get_class($exception),
            ]);
        }

        if ($status === 'healthy') {
            if (in_array($previous, ['critical', 'warning', 'unknown'], true)) {
                $this->emitAlert(
                    'recovered',
                    'سلامت فراست بازیابی شد',
                    'بررسی عملیاتی بعدی وضعیت همه اجزای پایش‌شده را سالم گزارش کرد.',
                    $snapshot,
                    $notifications,
                    $auditEvents
                );
            }

            $this->info('FARAST operational health is healthy.');

            return self::SUCCESS;
        }

        $components = (array) ($snapshot['components'] ?? []);
        $affected = [];
        foreach ($components as $name => $component) {
            $componentStatus = (string) ($component['status'] ?? 'unknown');
            if ($componentStatus !== 'healthy') {
                $affected[$name] = $componentStatus;
            }
        }

        $severity = $status === 'critical' ? 'critical' : 'warning';
        $cooldown = max(5, min(1440, (int) config('observability.alerts.cooldown_minutes', 30)));
        $bucket = (int) floor(now()->timestamp / ($cooldown * 60));
        $fingerprint = hash('sha256', json_encode([$severity, $affected], JSON_UNESCAPED_SLASHES) ?: $severity);
        $gateKey = 'farast:observability:alert:'.$fingerprint.':'.$bucket;
        $shouldNotify = false;

        try {
            $shouldNotify = Cache::add($gateKey, true, now()->addMinutes($cooldown));
        } catch (Throwable $exception) {
            // A cache outage must not suppress the durable operational log.
            Log::warning('farast.observability.alert_deduplication_unavailable', [
                'exception_class' => get_class($exception),
            ]);
            $shouldNotify = $previous !== $status;
        }

        if ($shouldNotify) {
            $title = $severity === 'critical'
                ? 'هشدار بحرانی سلامت فراست'
                : 'هشدار وضعیت سلامت فراست';
            $body = 'اجزای نیازمند بررسی: '.implode('، ', array_map(
                static fn ($name, $componentStatus) => $name.' ('.$componentStatus.')',
                array_keys($affected),
                array_values($affected)
            ));
            $this->emitAlert($severity, $title, $body, $snapshot, $notifications, $auditEvents);
        }

        Log::log($severity === 'critical' ? 'critical' : 'warning', 'farast.observability.health_degraded', [
            'status' => $status,
            'affected_components' => $affected,
        ]);

        $this->line('FARAST operational health: '.$status);

        return $severity === 'critical' ? self::FAILURE : self::SUCCESS;
    }

    private function emitAlert(
        string $severity,
        string $title,
        string $body,
        array $snapshot,
        NotificationService $notifications,
        AuditEventService $auditEvents
    ): void {
        $affected = [];
        foreach ((array) ($snapshot['components'] ?? []) as $name => $component) {
            $componentStatus = (string) ($component['status'] ?? 'unknown');
            if ($componentStatus !== 'healthy') {
                $affected[$name] = $componentStatus;
            }
        }

        try {
            $auditEvents->record(
                'observability.incident.'.$severity,
                null,
                null,
                'observability',
                null,
                [
                    'status' => $snapshot['status'] ?? 'unknown',
                    'affected_components' => $affected,
                    'severity' => $severity,
                ],
                'operations'
            );
        } catch (Throwable $exception) {
            // The alert log remains the fallback if the database/audit ledger is unavailable.
            Log::critical('farast.observability.incident_audit_failed', [
                'severity' => $severity,
                'exception_class' => get_class($exception),
            ]);
        }

        $userIds = (array) config('observability.alerts.user_ids', []);
        if ($userIds === []) {
            Log::notice('farast.observability.alert_recipients_not_configured', [
                'severity' => $severity,
            ]);
            return;
        }

        $url = route('admin.observability.index');
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            try {
                $notifications->push(
                    (int) $user->id,
                    'security_operational_alert',
                    $title,
                    $body,
                    [
                        'severity' => $severity,
                        'status' => $snapshot['status'] ?? 'unknown',
                        'url' => $url,
                    ],
                    false,
                    'farast-observability:'.Str::uuid()
                );
            } catch (Throwable $exception) {
                Log::warning('farast.observability.notification_failed', [
                    'recipient_id' => $user->id,
                    'exception_class' => get_class($exception),
                ]);
            }
        }
    }
}
