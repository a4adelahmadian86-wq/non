<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Services\FarastTraceContext;
use App\Services\SupportTicketService;
use Illuminate\Auth\Events\Login;
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
                app(FarastTraceContext::class)->clear();
                Log::withoutContext();
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
                app(FarastTraceContext::class)->clear();
                Log::withoutContext();
            }
        });

        Event::listen(Login::class, function (Login $event): void {
            try {
                app(SupportTicketService::class)->claimGuestTickets($event->user);
            } catch (Throwable $exception) {
                // Ticket claiming must not make authentication unavailable, but failures
                // must remain visible to operators and the central error tracker.
                report($exception);
            }
        });
    }
}
