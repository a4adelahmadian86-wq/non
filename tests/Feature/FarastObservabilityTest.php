<?php

namespace Tests\Feature;

use App\Http\Middleware\FarastObservability;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FarastObservabilityTest extends TestCase
{
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
        $this->assertSame(['request_id' => null, 'correlation_id' => null], app(\\App\\Services\\FarastTraceContext::class)->current());

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

        $job = \\Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'farast_trace' => [
                'request_id' => 'request-queue-123',
                'correlation_id' => 'correlation-queue-456',
            ],
        ]);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ExampleJob');
        $job->shouldReceive('getJobId')->andReturn('job-789');
        $job->shouldReceive('getQueue')->andReturn('critical');

        Event::dispatch(new JobFailed('database', $job, new \\RuntimeException('expected test failure')));

        Log::shouldHaveReceived('error')->once()->with(
            'farast.queue.job.failed',
            \\Mockery::on(function (array $context): bool {
                return ($context['request_id'] ?? null) === 'request-queue-123'
                    && ($context['correlation_id'] ?? null) === 'correlation-queue-456'
                    && ($context['job_id'] ?? null) === 'job-789'
                    && ($context['queue'] ?? null) === 'critical'
                    && ($context['exception_class'] ?? null) === \\RuntimeException::class;
            })
        );
    }
}
