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
