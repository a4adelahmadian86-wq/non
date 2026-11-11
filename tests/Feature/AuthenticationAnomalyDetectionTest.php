<?php

namespace Tests\Feature;

use Illuminate\Auth\Events\Failed as AuthenticationFailed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AuthenticationAnomalyDetectionTest extends TestCase
{
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

        Log::shouldHaveReceived('log')->once()->with(
            'warning',
            'farast.security.authentication_anomaly',
            Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return ($context['failed_attempts'] ?? null) === 5
                    && ($context['threshold'] ?? null) === 5
                    && ! str_contains((string) $encoded, '203.0.113.10')
                    && ! str_contains((string) $encoded, 'private@example.test')
                    && ! str_contains((string) $encoded, 'never-log-this');
            })
        );

        Log::shouldHaveReceived('log')->once()->with(
            'critical',
            'farast.security.authentication_anomaly',
            Mockery::on(fn (array $context): bool => ($context['failed_attempts'] ?? null) === 10
                && ($context['threshold'] ?? null) === 10)
        );
    }
}
