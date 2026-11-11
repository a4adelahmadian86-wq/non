<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditLogService
{
    public function record(?int $userId, string $action, ?string $ip, array $meta = []): void
    {
        $request = app()->bound('request') ? request() : null;
        if ($request) {
            $requestId = $request->attributes->get('farast.request_id');
            $correlationId = $request->attributes->get('farast.correlation_id');

            if (is_string($requestId) && $requestId !== '') {
                $meta['request_id'] ??= $requestId;
            }
            if (is_string($correlationId) && $correlationId !== '') {
                $meta['correlation_id'] ??= $correlationId;
            }
        }

        $safeMeta = $this->sanitize($meta);
        $encoded = json_encode($safeMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || strlen($encoded) > 16000) {
            $encoded = json_encode([
                '_truncated' => true,
                'request_id' => $meta['request_id'] ?? null,
                'correlation_id' => $meta['correlation_id'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => mb_substr($action, 0, 120),
            'ip' => $ip,
            'meta' => $encoded,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sanitize(array $data, int $depth = 0): array
    {
        if ($depth >= 5) {
            return ['_truncated' => true];
        }

        $safe = [];
        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            $name = (string) $key;
            if (preg_match('/password|secret|token|authorization|cookie|api[_-]?key|credential|recovery[_-]?code|otp/i', $name)) {
                $safe[$name] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $safe[$name] = $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $safe[$name] = mb_substr($value, 0, 2000);
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
