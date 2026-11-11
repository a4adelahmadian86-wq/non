<?php

namespace Tests\Feature;

use Illuminate\Auth\Events\Failed as AuthenticationFailed;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AuthenticationAnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;
    public function test_repeated_authentication_failures_emit_threshold_alerts_without_logging_ip_or_credentials(): void
    {
        Cache::flush();
        Log::spy();
        app()->instance('request', Request::create(
            '/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.10']
        ));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            Event::dispatch(new AuthenticationFailed('web', null, [
                'email' => 'private@example.test',
                'password' => 'never-log-this',
            ]));
        }

        $anomalyCalls = [];
        Log::shouldHaveReceived('log')->twice()->withArgs(function ($level, $message, $context) use (&$anomalyCalls): bool {
            if ($message !== 'farast.security.authentication_anomaly') {
                return false;
            }

            $anomalyCalls[] = [$level, $context];
            return true;
        });

        $this->assertSame(['warning', 'critical'], array_column($anomalyCalls, 0));
        $this->assertSame([5, 10], array_map(fn (array $call) => $call[1]['threshold'] ?? null, $anomalyCalls));
        $warningContext = $anomalyCalls[0][1];
        $encoded = json_encode($warningContext);
        $this->assertSame(5, $warningContext['failed_attempts'] ?? null);
        $this->assertStringNotContainsString('203.0.113.10', (string) $encoded);
        $this->assertStringNotContainsString('private@example.test', (string) $encoded);
        $this->assertStringNotContainsString('never-log-this', (string) $encoded);
    }
}
