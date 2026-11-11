<?php

namespace Tests\Feature;

use App\Http\Controllers\ReadinessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReadinessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_endpoint_returns_ready_when_primary_database_is_reachable(): void
    {
        $response = $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_readiness_failure_is_generic_and_does_not_leak_database_errors(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new RuntimeException('private database host and credentials'));

        $response = app(ReadinessController::class)();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['status' => 'not_ready'], $response->getData(true));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringNotContainsString('private database host', $response->getContent());
    }


    public function test_readiness_route_reaches_the_probe_when_database_backed_sessions_are_unavailable(): void
    {
        config(['session.driver' => 'database']);

        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new RuntimeException('private database host and credentials'));

        $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready'])
            ->assertHeader('Cache-Control', 'no-store, max-age=0');
    }
}
