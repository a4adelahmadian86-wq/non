<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;
use Symfony\\Component\\HttpFoundation\\Response;

class FarastObservability
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->safeIdentifier($request->header('X-Request-Id')) ?? (string) Str::uuid();
        $correlationId = $this->safeIdentifier($request->header('X-Correlation-Id')) ?? $requestId;

        Log::withContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);

        $started = hrtime(true);
        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            try {
                Log::info('farast.request.completed', [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response?->getStatusCode(),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ]);

                if ($response) {
                    $response->headers->set('X-Request-Id', $requestId);
                    $response->headers->set('X-Correlation-Id', $correlationId);
                }
            } finally {
                // Long-running workers must not leak one request's context into another.
                Log::withoutContext();
            }
        }
    }

    /**
     * Accept only bounded, single-line identifiers from trusted tracing headers.
     * Invalid or attacker-controlled values are replaced with a generated ID.
     */
    private function safeIdentifier(?string $value): ?string
    {
        if ($value === null || strlen($value) > 64) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
