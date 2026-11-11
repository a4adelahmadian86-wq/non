<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditEventService
{
    private const APPEND_LOCK_MIGRATION = '2026_09_30_000004_add_feedback_trust_and_audit_ledger';

    public function record(
        string $eventType,
        ?int $userId = null,
        ?int $projectId = null,
        ?string $aggregateType = null,
        ?int $aggregateId = null,
        array $payloadMeta = [],
        string $source = 'application'
    ): FarastAuditEvent {
        $payloadMeta = $this->withTraceContext($this->redactMetadata($payloadMeta));

        return DB::transaction(function () use ($eventType, $userId, $projectId, $aggregateType, $aggregateId, $payloadMeta, $source): FarastAuditEvent {
            // A no-op update serializes appenders on every supported database.
            // SQLite obtains its writer lock; row-locking databases retain the
            // migration-row lock until this transaction commits.
            $locked = DB::table('migrations')
                ->where('migration', self::APPEND_LOCK_MIGRATION)
                ->update(['batch' => DB::raw('batch')]);

            if ($locked !== 1) {
                throw new \RuntimeException('The canonical audit append lock is unavailable.');
            }

            $previous = FarastAuditEvent::query()
                ->orderByDesc('id')
                ->value('payload_checksum');

            $payload = [
                'event_type' => $eventType,
                'user_id' => $userId,
                'project_id' => $projectId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'source' => $source,
                'payload_meta' => $payloadMeta,
            ];

            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return FarastAuditEvent::create([
                'event_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'project_id' => $projectId,
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'source' => $source,
                'payload_meta' => $payloadMeta,
                'payload_checksum' => hash('sha256', $encoded),
                'previous_checksum' => $previous,
            ]);
        }, 3);
    }

    private function withTraceContext(array $metadata): array
    {
        unset($metadata['request_id'], $metadata['correlation_id']);

        $trace = app(FarastTraceContext::class);
        if ($trace->isActive()) {
            $current = $trace->current();
        } else {
            $request = app()->bound('request') ? app('request') : null;
            $current = [
                'request_id' => $request?->attributes?->get('farast.request_id'),
                'correlation_id' => $request?->attributes?->get('farast.correlation_id'),
            ];
        }

        foreach (['request_id', 'correlation_id'] as $key) {
            $value = $current[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function redactMetadata(array $metadata): array
    {
        $redacted = [];
        foreach ($metadata as $key => $value) {
            $redacted[$key] = $this->redactValue($value, (string) $key);
        }

        return $redacted;
    }

    private function redactValue(mixed $value, string $key): mixed
    {
        if (preg_match('/password|passphrase|secret|token|api[_-]?key|authorization|cookie|credential|private[_-]?key/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->redactValue($childValue, (string) $childKey);
            }

            return $safe;
        }

        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback(
            '/([?&][^=&#]*(?:token|secret|password|api[_-]?key|authorization|credential)[^=&#]*=)[^&#]*/i',
            static fn (array $match): string => $match[1].'[REDACTED]',
            $value
        ) ?? $value;
    }
}
