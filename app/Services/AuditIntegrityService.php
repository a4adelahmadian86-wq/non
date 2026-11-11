<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use RuntimeException;

class AuditIntegrityService
{
    /**
     * Return one deterministic serialization for the checksum chain.
     * Associative keys are sorted recursively; list order is preserved.
     */
    public function canonicalPayload(array $payload): string
    {
        $encoded = json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Audit payload could not be serialized.');
        }

        return $encoded;
    }

    public function verify(): array
    {
        $previous = null;
        $checked = 0;
        $errors = [];

        FarastAuditEvent::query()->orderBy('id')->chunkById(500, function ($rows) use (&$previous, &$checked, &$errors): void {
            foreach ($rows as $row) {
                $payload = [
                    'event_type' => $row->event_type,
                    'user_id' => $row->user_id,
                    'project_id' => $row->project_id,
                    'aggregate_type' => $row->aggregate_type,
                    'aggregate_id' => $row->aggregate_id,
                    'source' => $row->source,
                    'payload_meta' => $row->payload_meta,
                ];

                $canonicalPayload = $this->canonicalPayload($payload);
                $checksum = (string) $row->payload_checksum;

                if (is_string($row->payload_canonical) && $row->payload_canonical !== '') {
                    // New rows keep the exact canonical serialization in TEXT so
                    // MySQL JSON key normalization cannot change the hashed bytes.
                    $payloadMatches = hash_equals($row->payload_canonical, $canonicalPayload)
                        && hash_equals($checksum, hash('sha256', $row->payload_canonical));
                } else {
                    // Backward compatibility for rows written before the canonical
                    // serialization column existed.
                    $legacyPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $legacyMatches = is_string($legacyPayload)
                        && hash_equals($checksum, hash('sha256', $legacyPayload));
                    $canonicalMatches = hash_equals($checksum, hash('sha256', $canonicalPayload));
                    $payloadMatches = $legacyMatches || $canonicalMatches;
                }

                if ($row->previous_checksum !== $previous || ! $payloadMatches) {
                    $errors[] = ['id' => $row->id, 'event_id' => $row->event_id];
                }

                $previous = $row->payload_checksum;
                $checked++;
            }
        });

        return ['ok' => empty($errors), 'checked' => $checked, 'errors' => $errors];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[(string) $key] = $this->canonicalize($item);
        }
        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
