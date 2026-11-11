<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Services\FarastTraceContext;
use App\Services\SupportTicketService;
use Illuminate\Auth\Events\Failed as AuthenticationFailed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupportTicketService::class);
        $this->app->singleton(FarastTraceContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);

        // Carry the originating HTTP trace into queued jobs. A worker can then
        // propagate the same correlation ID to any child jobs it dispatches.
        Queue::createPayloadUsing(function (): array {
            if (! config('observability.enabled', true)) {
                return [];
            }

            $trace = app(FarastTraceContext::class)->current();
            if (! $trace['correlation_id']) {
                $trace['correlation_id'] = (string) Str::uuid();
            }

            return ['farast_trace' => $trace];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            try {
                $trace = (array) ($event->job->payload()['farast_trace'] ?? []);
                $requestId = is_string($trace['request_id'] ?? null) ? $trace['request_id'] : null;
                $correlationId = is_string($trace['correlation_id'] ?? null) ? $trace['correlation_id'] : null;

                app(FarastTraceContext::class)->set($requestId, $correlationId);
                Log::withContext(array_filter([
                    'request_id' => $requestId,
                    'correlation_id' => $correlationId,
                    'job_id' => $event->job->getJobId(),
                    'queue' => $event->job->getQueue(),
                ], static fn ($value) => $value !== null));
                Log::info('farast.queue.job.started', [
                    'job_name' => $event->job->resolveName(),
                    'attempt' => $event->job->attempts(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST queue trace initialization failed: '.get_class($exception));
            }
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            try {
                Log::info('farast.queue.job.completed', [
                    'job_name' => $event->job->resolveName(),
                    'job_id' => $event->job->getJobId(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST queue completion logging failed: '.get_class($exception));
            } finally {
                $this->clearQueueTrace();
            }
        });

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            try {
                Log::error('farast.queue.job.exception', [
                    'job_name' => $event->job->resolveName(),
                    'job_id' => $event->job->getJobId(),
                    'exception_class' => get_class($event->exception),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST queue exception logging failed: '.get_class($exception));
            } finally {
                $this->clearQueueTrace();
            }
        });

        // JobExceptionOccurred is emitted for each failed attempt. JobFailed is
        // emitted only when the worker gives up; log that terminal state from the
        // immutable payload because the per-attempt listener clears worker context.
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            try {
                $trace = (array) ($event->job->payload()['farast_trace'] ?? []);
                Log::error('farast.queue.job.failed', array_filter([
                    'request_id' => is_string($trace['request_id'] ?? null) ? $trace['request_id'] : null,
                    'correlation_id' => is_string($trace['correlation_id'] ?? null) ? $trace['correlation_id'] : null,
                    'job_name' => $event->job->resolveName(),
                    'job_id' => $event->job->getJobId(),
                    'queue' => $event->job->getQueue(),
                    'exception_class' => get_class($event->exception),
                ], static fn ($value) => $value !== null));
            } catch (Throwable $exception) {
                error_log('FARAST terminal queue failure logging failed: '.get_class($exception));
            } finally {
                $this->clearQueueTrace();
            }
        });

        Event::listen(AuthenticationFailed::class, function (AuthenticationFailed $event): void {
            // Never log the submitted credentials or the attempted email/phone.
            try {
                Log::warning('farast.security.authentication_failed', [
                    'guard' => $event->guard,
                    'actor_id' => $event->user?->getAuthIdentifier(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST authentication failure logging failed: '.get_class($exception));
            }
        });

        Event::listen(Login::class, function (Login $event): void {
            try {
                Log::info('farast.security.authentication_succeeded', [
                    'guard' => $event->guard,
                    'actor_id' => $event->user->getAuthIdentifier(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST authentication success logging failed: '.get_class($exception));
            }

            try {
                app(SupportTicketService::class)->claimGuestTickets($event->user);
            } catch (Throwable $exception) {
                // Ticket claiming must not make authentication unavailable, but failures
                // must remain visible to operators and the central error tracker.
                report($exception);
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            try {
                Log::info('farast.security.logout', [
                    'guard' => $event->guard,
                    'actor_id' => $event->user?->getAuthIdentifier(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST logout logging failed: '.get_class($exception));
            }
        });

        Event::listen(Lockout::class, function (Lockout $event): void {
            try {
                Log::warning('farast.security.authentication_lockout', [
                    'route' => $event->request->route()?->getName(),
                    'path' => $event->request->path(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST authentication lockout logging failed: '.get_class($exception));
            }
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            try {
                Log::notice('farast.security.password_reset', [
                    'actor_id' => $event->user->getAuthIdentifier(),
                ]);
            } catch (Throwable $exception) {
                error_log('FARAST password reset logging failed: '.get_class($exception));
            }
        });
    }

    private function clearQueueTrace(): void
    {
        try {
            app(FarastTraceContext::class)->clear();
        } catch (Throwable $exception) {
            error_log('FARAST queue trace cleanup failed: '.get_class($exception));
        }

        try {
            Log::withoutContext();
        } catch (Throwable $exception) {
            error_log('FARAST queue log context cleanup failed: '.get_class($exception));
        }
    }
}
