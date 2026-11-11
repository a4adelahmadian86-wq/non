<?php

namespace App\Http\Middleware;

use App\Services\FarastTraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FarastObservability
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.enabled', true)) {
            return $next($request);
        }

        $requestId = $this->safeIdentifier($request->header('X-Request-Id')) ?? (string) Str::uuid();
        $correlationId = $this->safeIdentifier($request->header('X-Correlation-Id')) ?? $requestId;

        $request->attributes->set('farast.request_id', $requestId);
        $request->attributes->set('farast.correlation_id', $correlationId);
        // Exception handlers also need generated IDs when the client sent no trace headers.
        $request->headers->set('X-Request-Id', $requestId);
        $request->headers->set('X-Correlation-Id', $correlationId);

        try {
            app(FarastTraceContext::class)->set($requestId, $correlationId);
            Log::withContext([
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
            ]);
        } catch (Throwable $exception) {
            // Observability is best-effort: a logging outage must not take down the app.
            error_log('FARAST request context initialization failed: '.get_class($exception));
        }

        $started = hrtime(true);
        $response = null;
        $failure = null;

        try {
            $response = $next($request);

            return $response;
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try {
                try {
                    $context = [
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'route' => $request->route()?->getName(),
                        'actor_id' => $request->user()?->getAuthIdentifier(),
                        'status' => $response?->getStatusCode(),
                        'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                    ];
                    if ($failure) {
                        $context['exception_class'] = get_class($failure);
                        Log::error('farast.request.failed', $context);
                    } else {
                        Log::info('farast.request.completed', $context);
                    }
                    if ($response?->getStatusCode() === 403) {
                        $this->recordAuthorizationAnomaly($request);
                    }
                } catch (Throwable $exception) {
                    error_log('FARAST request completion logging failed: '.get_class($exception));
                }

                if ($response) {
                    $response->headers->set('X-Request-Id', $requestId);
                    $response->headers->set('X-Correlation-Id', $correlationId);
                }
            } finally {
                // Long-running workers must not leak one request's context into another.
                try {
                    Log::withoutContext();
                } catch (Throwable $exception) {
                    error_log('FARAST request log context cleanup failed: '.get_class($exception));
                } finally {
                    try {
                        app(FarastTraceContext::class)->clear();
                    } catch (Throwable $exception) {
                        error_log('FARAST trace context cleanup failed: '.get_class($exception));
                    }
                }
            }
        }
    }


    private function recordAuthorizationAnomaly(Request $request): void
    {
        try {
            $actorId = $request->user()?->getAuthIdentifier();
            $source = $actorId !== null ? 'user:'.$actorId : (string) $request->ip();
            if ($source === '') {
                return;
            }

            $window = max(1, (int) config('observability.security.authorization_denied_window_minutes', 10));
            $fingerprint = hash_hmac('sha256', $source, (string) config('app.key'));
            $key = 'farast:security:authorization-denied:'.$fingerprint;
            \Illuminate\Support\Facades\Cache::add($key, 0, now()->addMinutes($window));
            $count = (int) \Illuminate\Support\Facades\Cache::increment($key);

            $warningThreshold = max(2, (int) config('observability.security.authorization_denied_warning_threshold', 10));
            $criticalThreshold = max($warningThreshold + 1, (int) config('observability.security.authorization_denied_critical_threshold', 25));

            foreach ([$warningThreshold => 'warning', $criticalThreshold => 'critical'] as $threshold => $level) {
                if ($count < $threshold || ! \Illuminate\Support\Facades\Cache::add($key.':alert:'.$threshold, true, now()->addMinutes($window))) {
                    continue;
                }

                $metadata = [
                    'source_fingerprint' => substr($fingerprint, 0, 16),
                    'denied_requests' => $count,
                    'threshold' => $threshold,
                    'window_minutes' => $window,
                    'route' => $request->route()?->getName(),
                ];
                Log::log($level, 'farast.security.authorization_anomaly', $metadata);

                try {
                    app(\App\Services\AuditEventService::class)->record(
                        'security.authorization_anomaly',
                        null,
                        null,
                        'authorization',
                        null,
                        $metadata + ['severity' => $level],
                        'security'
                    );
                } catch (Throwable $auditException) {
                    Log::critical('farast.security.authorization_anomaly_audit_failed', [
                        'exception_class' => get_class($auditException),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            // An unavailable cache or audit store must never alter the authorization response.
            error_log('FARAST authorization anomaly detection failed: '.get_class($exception));
        }
    }

    /**
     * Accept only bounded, single-line identifiers from tracing headers.
     * Invalid or attacker-controlled values are replaced with a generated ID.
     */
    private function safeIdentifier(?string $value): ?string
    {
        if ($value === null || strlen($value) > 64) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
