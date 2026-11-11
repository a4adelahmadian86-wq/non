<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FarastObservabilityService
{
    private const INCIDENT_WINDOW_HOURS = 24;

    private const INCIDENT_SAMPLE_LIMIT = 500;

    public function __construct(
        private readonly ProductInfrastructureHealthService $productInfrastructure
    ) {
    }

    /**
     * Return a bounded, secret-free operational snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $components = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'logs' => $this->logs(),
        ];

        try {
            $product = $this->productInfrastructure->snapshot();
            $components['product_supervisor'] = $this->normalizeProbe($product['supervisor'] ?? null);
            $components['document_audio'] = $this->normalizeProbe($product['document_audio'] ?? null);
        } catch (Throwable) {
            $components['product_supervisor'] = $this->component('unavailable');
            $components['document_audio'] = $this->component('unavailable');
        }

        $statuses = array_column($components, 'status');
        $status = in_array('critical', $statuses, true) || in_array('unavailable', $statuses, true)
            ? 'critical'
            : (count(array_filter($statuses, static fn ($value) => $value !== 'healthy')) > 0 ? 'warning' : 'healthy');

        $history = $this->incidentHistory();

        return [
            'status' => $status,
            'components' => $components,
            'incident_history_available' => $history['available'],
            'incident_trend_24h' => $history['trend'],
            'incidents' => $history['incidents'],
        ];
    }

    private function database(): array
    {
        try {
            DB::select('SELECT 1');

            return $this->component('healthy', ['driver' => DB::connection()->getDriverName()]);
        } catch (Throwable) {
            return $this->component('critical');
        }
    }

    private function queue(): array
    {
        $driver = (string) config('queue.default', 'unknown');

        if ($driver === 'sync') {
            return $this->component('not_measurable', ['driver' => $driver, 'pending_jobs' => null, 'failed_jobs' => null]);
        }

        if ($driver !== 'database') {
            return $this->component('not_measurable', ['driver' => $driver, 'pending_jobs' => null, 'failed_jobs' => null]);
        }

        try {
            if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
                return $this->component('degraded', ['driver' => $driver, 'pending_jobs' => null, 'failed_jobs' => null]);
            }

            $pending = (int) DB::table('jobs')->count();
            $failed = (int) DB::table('failed_jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');

            return $this->component('healthy', [
                'driver' => $driver,
                'pending_jobs' => $pending,
                'failed_jobs' => $failed,
                'oldest_pending_at' => $oldest,
            ]);
        } catch (Throwable) {
            return $this->component('unavailable', ['driver' => $driver]);
        }
    }

    private function storage(): array
    {
        $path = storage_path('app/private');
        $probe = $path.'/.farast-health-'.bin2hex(random_bytes(8));

        try {
            if (! is_dir($path) && ! @mkdir($path, 0770, true) && ! is_dir($path)) {
                return $this->component('unavailable');
            }

            if (@file_put_contents($probe, 'ok', LOCK_EX) !== 2) {
                return $this->component('unavailable');
            }

            $readable = @file_get_contents($probe) === 'ok';
            @unlink($probe);

            return $this->component($readable ? 'healthy' : 'unavailable');
        } catch (Throwable) {
            @unlink($probe);

            return $this->component('unavailable');
        }
    }

    private function logs(): array
    {
        $path = storage_path('logs');

        return $this->component(is_dir($path) && is_writable($path) ? 'healthy' : 'degraded');
    }

    private function normalizeProbe(mixed $probe): array
    {
        if (! is_array($probe)) {
            return $this->component('unavailable');
        }

        $status = (string) ($probe['status'] ?? 'unknown');
        $allowed = ['healthy', 'degraded', 'unavailable', 'not_configured', 'misconfigured', 'unknown'];
        if (! in_array($status, $allowed, true)) {
            $status = 'unknown';
        }

        return $this->component($status, array_intersect_key($probe, array_flip([
            'configured', 'checked_at', 'product_review_ready', 'file_review_ready',
            'privacy_gate_confirmed', 'model_ready', 'word_alignment_ready',
        ])));
    }

    private function eventSeverity(string $eventType): string
    {
        $parts = explode('.', $eventType);

        return (string) end($parts);
    }

    private function component(string $status, array $metadata = []): array
    {
        return ['status' => $status, 'checked_at' => now()->toISOString()] + $metadata;
    }

    /**
     * @return array{available: bool, trend: array<string, mixed>, incidents: array<int, array<string, mixed>>}
     */
    private function incidentHistory(): array
    {
        $unavailable = [
            'available' => false,
            'trend' => ['available' => false, 'sampled' => 0, 'critical' => 0, 'warning' => 0, 'recovered' => 0],
            'incidents' => [],
        ];

        try {
            if (! Schema::hasTable('farast_audit_events')) {
                return $unavailable;
            }

            $rows = FarastAuditEvent::query()
                ->where('event_type', 'like', 'observability.incident.%')
                ->where('created_at', '>=', now()->subHours(self::INCIDENT_WINDOW_HOURS))
                ->orderByDesc('id')
                ->limit(self::INCIDENT_SAMPLE_LIMIT)
                ->get();

            $counts = ['critical' => 0, 'warning' => 0, 'recovered' => 0];
            foreach ($rows as $row) {
                $meta = (array) ($row->payload_meta ?? []);
                $severity = (string) ($meta['severity'] ?? $this->eventSeverity($row->event_type));
                if (array_key_exists($severity, $counts)) {
                    $counts[$severity]++;
                }
            }

            $incidents = $rows->take(20)->map(function (FarastAuditEvent $row): array {
                $meta = (array) ($row->payload_meta ?? []);

                return [
                    'event_id' => $row->event_id,
                    'event_type' => $row->event_type,
                    'severity' => (string) ($meta['severity'] ?? $this->eventSeverity($row->event_type)),
                    'incident_id' => (string) ($meta['incident_id'] ?? $row->event_id),
                    'status' => $meta['status'] ?? null,
                    'affected_components' => (array) ($meta['affected_components'] ?? []),
                    'created_at' => $row->created_at?->toISOString(),
                ];
            })->all();

            return [
                'available' => true,
                'trend' => [
                    'available' => true,
                    'sampled' => $rows->count(),
                    'critical' => $counts['critical'],
                    'warning' => $counts['warning'],
                    'recovered' => $counts['recovered'],
                    'window_hours' => self::INCIDENT_WINDOW_HOURS,
                    'sample_limit' => self::INCIDENT_SAMPLE_LIMIT,
                ],
                'incidents' => $incidents,
            ];
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
