<?php

namespace Tests\Feature;

use App\Http\Middleware\FarastObservability;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FarastObservabilityTest extends TestCase
{
    use RefreshDatabase;
    public function test_it_preserves_bounded_safe_trace_identifiers(): void
    {
        Log::spy();

        $request = Request::create('/up', 'GET');
        $request->headers->set('X-Request-Id', 'req-123');
        $request->headers->set('X-Correlation-Id', 'trace.parent:456');

        $response = (new FarastObservability())->handle(
            $request,
            fn () => response('ok')
        );

        $this->assertSame('req-123', $response->headers->get('X-Request-Id'));
        $this->assertSame('trace.parent:456', $response->headers->get('X-Correlation-Id'));
        $this->assertSame('req-123', $request->attributes->get('farast.request_id'));
        $this->assertSame('trace.parent:456', $request->attributes->get('farast.correlation_id'));
        $this->assertSame(['request_id' => null, 'correlation_id' => null], app(\App\Services\FarastTraceContext::class)->current());

        Log::shouldHaveReceived('withContext')->once()->with([
            'request_id' => 'req-123',
            'correlation_id' => 'trace.parent:456',
        ]);
        Log::shouldHaveReceived('info')->once();
        Log::shouldHaveReceived('withoutContext')->once();
    }

    public function test_it_replaces_invalid_or_oversized_trace_identifiers(): void
    {
        $request = Request::create('/up', 'GET');
        $request->headers->set('X-Request-Id', 'invalid id with spaces');
        $request->headers->set('X-Correlation-Id', str_repeat('x', 65));

        $response = (new FarastObservability())->handle(
            $request,
            fn () => response('ok')
        );

        $requestId = (string) $response->headers->get('X-Request-Id');
        $correlationId = (string) $response->headers->get('X-Correlation-Id');

        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/i', $requestId);
        $this->assertSame($requestId, $correlationId);
    }

    public function test_logging_failures_do_not_break_the_request(): void
    {
        Log::shouldReceive('withContext')->once()->andThrow(new \RuntimeException('logger unavailable'));
        Log::shouldReceive('info')->once()->andThrow(new \RuntimeException('logger unavailable'));
        Log::shouldReceive('withoutContext')->once()->andThrow(new \RuntimeException('logger unavailable'));

        $request = Request::create('/up', 'GET');
        $response = (new FarastObservability())->handle(
            $request,
            fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_disabled_observability_passes_through_without_touching_the_logger(): void
    {
        config(['observability.enabled' => false]);
        Log::shouldReceive('withContext')->never();
        Log::shouldReceive('info')->never();
        Log::shouldReceive('withoutContext')->never();

        $request = Request::create('/up', 'GET');
        $response = (new FarastObservability())->handle(
            $request,
            fn () => response('ok', 200)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->headers->get('X-Request-Id'));
    }

    public function test_terminal_queue_failure_is_logged_with_original_trace_ids(): void
    {
        Log::spy();

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'farast_trace' => [
                'request_id' => 'request-queue-123',
                'correlation_id' => 'correlation-queue-456',
            ],
        ]);
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\ExampleJob');
        $job->shouldReceive('getJobId')->andReturn('job-789');
        $job->shouldReceive('getQueue')->andReturn('critical');

        Event::dispatch(new JobFailed('database', $job, new \RuntimeException('expected test failure')));

        Log::shouldHaveReceived('error')->once()->with(
            'farast.queue.job.failed',
            \Mockery::on(function (array $context): bool {
                return ($context['request_id'] ?? null) === 'request-queue-123'
                    && ($context['correlation_id'] ?? null) === 'correlation-queue-456'
                    && ($context['job_id'] ?? null) === 'job-789'
                    && ($context['queue'] ?? null) === 'critical'
                    && ($context['exception_class'] ?? null) === \RuntimeException::class;
            })
        );
    }

    public function test_queue_job_lifecycle_restores_and_clears_the_originating_trace(): void
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'farast_trace' => [
                'request_id' => 'origin-request-123',
                'correlation_id' => 'origin-correlation-456',
            ],
        ]);
        $job->shouldReceive('getJobId')->andReturn('job-123');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('resolveName')->andReturn('App\Jobs\TraceableJob');
        $job->shouldReceive('attempts')->andReturn(1);

        Event::dispatch(new JobProcessing('database', $job));

        $this->assertSame([
            'request_id' => 'origin-request-123',
            'correlation_id' => 'origin-correlation-456',
        ], app(\App\Services\FarastTraceContext::class)->current());

        Event::dispatch(new JobProcessed('database', $job));

        $this->assertSame([
            'request_id' => null,
            'correlation_id' => null,
        ], app(\App\Services\FarastTraceContext::class)->current());
    }

    public function test_repeated_forbidden_requests_emit_privacy_safe_authorization_anomaly_thresholds(): void
    {
        Cache::flush();
        Log::spy();

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $request = Request::create(
                '/admin/restricted',
                'GET',
                [],
                [],
                [],
                ['REMOTE_ADDR' => '203.0.113.44']
            );

            (new FarastObservability())->handle(
                $request,
                fn () => response('forbidden', 403)
            );
        }

        $anomalyCalls = [];
        Log::shouldHaveReceived('log')->twice()->withArgs(function ($level, $message, $context) use (&$anomalyCalls): bool {
            if ($message !== 'farast.security.authorization_anomaly') {
                return false;
            }

            $anomalyCalls[] = [$level, $context];
            return true;
        });

        $this->assertSame(['warning', 'critical'], array_column($anomalyCalls, 0));
        $this->assertSame([10, 25], array_map(fn (array $call) => $call[1]['threshold'] ?? null, $anomalyCalls));
        $warningContext = $anomalyCalls[0][1];
        $this->assertSame(10, $warningContext['denied_requests'] ?? null);
        $this->assertStringNotContainsString('203.0.113.44', (string) json_encode($warningContext));
    }

    public function test_exception_responses_keep_request_and_correlation_headers(): void
    {
        Route::get('/_test/observability-failure', function () {
            throw new \RuntimeException('expected test exception');
        });

        $this->getJson('/_test/observability-failure', [
            'X-Request-Id' => 'exception-request-123',
            'X-Correlation-Id' => 'exception-correlation-456',
        ])->assertStatus(500)
            ->assertHeader('X-Request-Id', 'exception-request-123')
            ->assertHeader('X-Correlation-Id', 'exception-correlation-456');
    }
}
