<?php

namespace App\Services;

use App\Models\FarastAuditEvent;

class AuditIntegrityService
{
    private const MAX_ERROR_SAMPLES = 100;

    public function verify(): array
    {
        $previous = null;
        $checked = 0;
        $errorCount = 0;
        $errors = [];

        FarastAuditEvent::query()
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$previous, &$checked, &$errorCount, &$errors): void {
                foreach ($rows as $row) {
                    $checksumVersion = (int) ($row->payload_meta['_ledger_checksum_version'] ?? 1);
                    $payload = match ($checksumVersion) {
                        1 => [
                            'event_type' => $row->event_type,
                            'user_id' => $row->user_id,
                            'project_id' => $row->project_id,
                            'aggregate_type' => $row->aggregate_type,
                            'aggregate_id' => $row->aggregate_id,
                            'source' => $row->source,
                            'payload_meta' => $row->payload_meta,
                        ],
                        2 => [
                            'event_id' => $row->event_id,
                            'event_type' => $row->event_type,
                            'user_id' => $row->user_id,
                            'project_id' => $row->project_id,
                            'aggregate_type' => $row->aggregate_type,
                            'aggregate_id' => $row->aggregate_id,
                            'source' => $row->source,
                            'payload_meta' => $this->canonicalizeValue(is_array($row->payload_meta) ? $row->payload_meta : []),
                            'created_at' => (string) $row->getRawOriginal('created_at'),
                            'updated_at' => (string) $row->getRawOriginal('updated_at'),
                        ],
                        default => throw new \UnexpectedValueException('Unsupported audit checksum version.'),
                    };

                    $encoded = json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                    $expected = hash('sha256', $encoded);
                    $chainMismatch = ($row->previous_checksum ?? null) !== $previous;
                    $payloadMismatch = ! hash_equals((string) ($row->payload_checksum ?? ''), $expected);

                    if ($chainMismatch || $payloadMismatch) {
                        $errorCount++;
                        if (count($errors) < self::MAX_ERROR_SAMPLES) {
                            $errors[] = [
                                'id' => (int) $row->id,
                                'event_id' => (string) $row->event_id,
                            ];
                        }
                    }

                    $previous = $row->payload_checksum;
                    $checked++;
                }
            });

        return [
            'ok' => $errorCount === 0,
            'checked' => $checked,
            'error_count' => $errorCount,
            'errors' => $errors,
            'errors_truncated' => $errorCount > count($errors),
        ];
    }

    /**
     * Canonicalize JSON objects after database decoding. JSON arrays retain order.
     */
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
}
