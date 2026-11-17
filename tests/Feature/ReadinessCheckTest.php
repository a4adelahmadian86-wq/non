<?php

namespace Tests\Feature;

use App\Http\Controllers\ReadinessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_readiness_rejects_an_incomplete_support_submission_schema(): void
    {
        Schema::table('tickets', function ($table): void {
            $table->dropUnique('tickets_submission_scope_key_unique');
        });

        $response = $this->getJson(route('health.ready'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringNotContainsString('submission', $response->getContent());
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


    public function test_readiness_route_does_not_depend_on_database_backed_sessions(): void
    {
        config(['session.driver' => 'database']);
        Schema::dropIfExists('sessions');

        $response = $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }
}
