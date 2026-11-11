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
use Illuminate\Support\Facades\Http;
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
        try {
            Log::withContext(['correlation_id' => $correlationId, 'operation' => 'farast:observability:check']);
        } catch (Throwable $exception) {
            error_log('FARAST operational log context initialization failed: '.get_class($exception));
        }

        try {
            return $this->executeCheck($observability, $notifications, $auditEvents);
        } finally {
            try {
                Log::withoutContext();
            } catch (Throwable $exception) {
                error_log('FARAST operational log context cleanup failed: '.get_class($exception));
            }
            $trace->clear();
        }
    }

    private function executeCheck(
        FarastObservabilityService $observability,
        NotificationService $notifications,
        AuditEventService $auditEvents
    ): int {
        try {
            $snapshot = $observability->snapshot();
        } catch (Throwable $exception) {
            $this->safeLog('critical', 'farast.observability.snapshot_failed', [
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
            $this->safeLog('warning', 'farast.observability.alert_state_unavailable', [
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
            $this->safeLog('warning', 'farast.observability.alert_deduplication_unavailable', [
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

        $this->safeLog($severity === 'critical' ? 'critical' : 'warning', 'farast.observability.health_degraded', [
            'status' => $status,
            'affected_components' => $affected,
        ]);

        $this->line('FARAST operational health: '.$status);

        return $severity === 'critical' ? self::FAILURE : self::SUCCESS;
    }



    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable $exception) {
            error_log('FARAST operational logging failed: '.get_class($exception));
        }
    }

    private function deliverWebhook(
        string $severity,
        string $title,
        string $body,
        array $snapshot,
        array $affected,
        ?string $incidentId,
        string $alertKey
    ): void {
        $url = trim((string) config('observability.webhook.url', ''));
        if ($url === '') {
            return;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            $this->safeLog('error', 'farast.observability.webhook_misconfigured', [
                'reason' => 'https_required',
            ]);
            return;
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->withOptions(['allow_redirects' => false]);

            $token = trim((string) config('observability.webhook.token', ''));
            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, [
                'source' => 'FARAST',
                'incident_id' => $incidentId,
                'alert_key' => $alertKey,
                'severity' => $severity,
                'title' => $title,
                'message' => $body,
                'status' => $snapshot['status'] ?? 'unknown',
                'affected_components' => $affected,
                'correlation_id' => app(FarastTraceContext::class)->current()['correlation_id'],
                'occurred_at' => now()->toIso8601String(),
            ]);

            if (! $response->successful()) {
                $this->safeLog('warning', 'farast.observability.webhook_delivery_failed', [
                    'http_status' => $response->status(),
                ]);
                return;
            }

            $this->safeLog('info', 'farast.observability.webhook_delivered', [
                'incident_id' => $incidentId,
                'severity' => $severity,
            ]);
        } catch (Throwable $exception) {
            // Never log the destination URL or response body; webhook URLs commonly contain secrets.
            $this->safeLog('warning', 'farast.observability.webhook_delivery_failed', [
                'exception_class' => get_class($exception),
            ]);
        }
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

        $cooldown = max(5, min(1440, (int) config('observability.alerts.cooldown_minutes', 30)));
        $bucket = (int) floor(now()->timestamp / ($cooldown * 60));
        $alertKey = hash('sha256', json_encode([$severity, $affected, $bucket], JSON_UNESCAPED_SLASHES) ?: $severity);

        $incidentEvent = null;
        try {
            $incidentEvent = $auditEvents->record(
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
            $this->safeLog('critical', 'farast.observability.incident_audit_failed', [
                'severity' => $severity,
                'exception_class' => get_class($exception),
            ]);
        }

        $this->deliverWebhook($severity, $title, $body, $snapshot, $affected, $incidentEvent?->event_id, $alertKey);

        $userIds = (array) config('observability.alerts.user_ids', []);
        if ($userIds === []) {
            $this->safeLog('notice', 'farast.observability.alert_recipients_not_configured', [
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
                    'farast-observability:'.(int) $user->id.':'.$alertKey
                );
            } catch (Throwable $exception) {
                $this->safeLog('warning', 'farast.observability.notification_failed', [
                    'recipient_id' => $user->id,
                    'exception_class' => get_class($exception),
                ]);
            }
        }
    }
}
