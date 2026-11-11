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
        $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ready'])
            ->assertHeader('Cache-Control', 'no-store, max-age=0');
    }

    public function test_readiness_failure_is_generic_and_does_not_leak_database_errors(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new RuntimeException('private database host and credentials'));

        $response = app(ReadinessController::class)();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['status' => 'not_ready'], $response->getData(true));
        $this->assertSame('no-store, max-age=0', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('private database host', $response->getContent());
    }
}
