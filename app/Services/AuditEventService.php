<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditEventService
{
    public function record(string $eventType, ?int $userId = null, ?int $projectId = null, ?string $aggregateType = null, ?int $aggregateId = null, array $payloadMeta = [], string $source = 'application'): FarastAuditEvent
    {
        // The append-only audit ledger is the canonical audit source. Attach
        // the active request/job trace before checksumming so the trace is
        // covered by the ledger integrity chain.
        $trace = app(FarastTraceContext::class)->current();
        $request = app()->bound('request') ? request() : null;
        $hasActiveTrace = $trace['request_id'] !== null || $trace['correlation_id'] !== null;
        $requestId = $hasActiveTrace ? $trace['request_id'] : $request?->attributes->get('farast.request_id');
        $correlationId = $hasActiveTrace ? $trace['correlation_id'] : $request?->attributes->get('farast.correlation_id');

        if (is_string($requestId) && $requestId !== '') {
            $payloadMeta['request_id'] = $requestId;
        }
        if (is_string($correlationId) && $correlationId !== '') {
            $payloadMeta['correlation_id'] = $correlationId;
        }

        $payloadMeta = $this->sanitize($payloadMeta);

        return DB::transaction(function () use ($eventType, $userId, $projectId, $aggregateType, $aggregateId, $payloadMeta, $source) {
            $previous = FarastAuditEvent::query()->lockForUpdate()->latest('id')->value('payload_checksum');
            $payload = [
                'event_type' => $eventType,
                'user_id' => $userId,
                'project_id' => $projectId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'source' => $source,
                'payload_meta' => $payloadMeta,
            ];

            return FarastAuditEvent::create([
                'event_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'project_id' => $projectId,
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'source' => $source,
                'payload_meta' => $payloadMeta,
                'payload_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'previous_checksum' => $previous,
            ]);
        });
    }

    private function sanitize(array $data, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['_truncated' => true];
        }

        $safe = [];
        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            $name = (string) $key;
            if (preg_match('/password|secret|token|authorization|cookie|api[_-]?key|credential|recovery[_-]?code|otp|session|csrf|private[_-]?key|signature|auth/i', $name)) {
                $safe[$name] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $safe[$name] = $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $redacted = preg_replace(
                    '/([?&](?:access_token|api_key|key|password|secret|signature|token|code|session|csrf)=)[^&#]*/i',
                    '$1[REDACTED]',
                    $value
                );
                $safe[$name] = mb_substr(is_string($redacted) ? $redacted : '[REDACTED]', 0, 2000);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$name] = $value;
            } else {
                $safe[$name] = '['.get_debug_type($value).']';
            }
        }

        if (count($data) > 50) {
            $safe['_truncated'] = true;
        }

        return $safe;
    }
}
