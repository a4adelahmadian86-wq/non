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
        $payloadMeta['_ledger_checksum_version'] = 2;

        return DB::transaction(function () use ($eventType, $userId, $projectId, $aggregateType, $aggregateId, $payloadMeta, $source) {
            $this->lockLedgerAppend();

            // This is a locking/current read after the stable append lock. It
            // prevents repeatable-read snapshots from observing a stale chain head.
            $previous = FarastAuditEvent::query()->lockForUpdate()->latest('id')->value('payload_checksum');
            $eventId = (string) Str::uuid();
            $timestamp = now()->format('Y-m-d H:i:s');
            $payload = [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'user_id' => $userId,
                'project_id' => $projectId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'source' => $source,
                'payload_meta' => $payloadMeta,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $payload = $this->normalizePayload($payload);

            $event = new FarastAuditEvent([
                'event_id' => $payload['event_id'],
                'user_id' => $payload['user_id'],
                'project_id' => $payload['project_id'],
                'event_type' => $payload['event_type'],
                'aggregate_type' => $payload['aggregate_type'],
                'aggregate_id' => $payload['aggregate_id'],
                'source' => $payload['source'],
                'payload_meta' => $payload['payload_meta'],
                'payload_checksum' => hash('sha256', json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )),
                'previous_checksum' => $previous,
            ]);
            $event->forceFill([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->save();

            return $event;
        });
    }

    /**
     * Serialize every append through a stable row that exists because the
     * canonical ledger migration must exist before this table can be used.
     *
     * MySQL/PostgreSQL hold the row lock until the outer transaction commits.
     * SQLite has no SELECT FOR UPDATE; a no-op UPDATE obtains its database
     * writer lock before the chain head is read. No new lock/audit table is
     * introduced, and the migration record is never changed.
     */
    private function lockLedgerAppend(): void
    {
        $connection = DB::connection();
        $migration = '2026_09_30_000004_add_feedback_trust_and_audit_ledger';

        if ($connection->getDriverName() === 'sqlite') {
            $connection->table('migrations')
                ->where('migration', $migration)
                ->update(['batch' => DB::raw('batch')]);

            if (! $connection->table('migrations')->where('migration', $migration)->exists()) {
                throw new \RuntimeException('Canonical audit ledger migration lock is unavailable.');
            }

            return;
        }

        $lock = $connection->table('migrations')
            ->where('migration', $migration)
            ->lockForUpdate()
            ->first(['id']);

        if (! $lock) {
            throw new \RuntimeException('Canonical audit ledger migration lock is unavailable.');
        }
    }

    /**
     * Normalize invalid UTF-8 before persistence so the stored JSON and the
     * checksummed representation are byte-for-byte equivalent.
     */
    private function normalizePayload(array $payload): array
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $normalized = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($normalized)) {
            throw new \UnexpectedValueException('Audit payload must serialize to an object.');
        }

        // MySQL normalizes key order inside native JSON columns. Canonicalize
        // metadata before hashing so SQLite/MySQL/PostgreSQL verify identical bytes.
        $normalized['payload_meta'] = $this->canonicalizeValue($normalized['payload_meta'] ?? []);

        return $normalized;
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalizeValue($nested);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function sanitize(array $data, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['_truncated' => true];
        }

        $safe = [];
        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            $name = (string) $key;
            if (preg_match('/password|secret|token|authorization|cookie|api[_-]?key|credential|recovery[_-]?code|otp|session|csrf|private[_-]?key|signature|authentication|auth[_-]?token/i', $name)) {
                $safe[$name] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $safe[$name] = $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $redacted = preg_replace(
                    '/([?&](?:access_token|api_key|key|password|secret|signature|token|code|session|csrf|auth_token)=)[^&#]*/i',
                    '$1[REDACTED]',
                    $value
                );
                $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
                $encodedString = json_encode($redacted, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $normalizedString = json_decode($encodedString, true, 512, JSON_THROW_ON_ERROR);
                $safe[$name] = mb_substr(is_string($normalizedString) ? $normalizedString : '[REDACTED]', 0, 2000);
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
