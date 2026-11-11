<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FarastObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CheckObservabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_alert_is_sent_once_per_cooldown_and_recovery_is_notified(): void
    {
        $recipient = User::create([
            'name' => 'مدیر عملیات',
            'mobile' => '09150000991',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        config([
            'observability.alerts.user_ids' => [$recipient->id],
            'observability.alerts.cooldown_minutes' => 30,
            'observability.webhook.url' => 'https://alerts.example.test/farast',
            'observability.webhook.token' => 'webhook-secret',
        ]);
        Http::fake();

        $observability = \Mockery::mock(FarastObservabilityService::class);
        $observability->shouldReceive('snapshot')->twice()->andReturn([
            'status' => 'critical',
            'components' => ['database' => ['status' => 'critical']],
        ]);
        app()->instance(FarastObservabilityService::class, $observability);

        $this->assertSame(1, Artisan::call('farast:observability:check'));
        $this->assertSame(1, Artisan::call('farast:observability:check'));
        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $recipient->id,
            'type' => 'security_operational_alert',
            'title' => 'هشدار بحرانی سلامت فراست',
        ]);

        $recovery = \Mockery::mock(FarastObservabilityService::class);
        $recovery->shouldReceive('snapshot')->once()->andReturn([
            'status' => 'healthy',
            'components' => ['database' => ['status' => 'healthy']],
        ]);
        app()->instance(FarastObservabilityService::class, $recovery);

        $this->assertSame(0, Artisan::call('farast:observability:check'));
        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $recipient->id,
            'type' => 'security_operational_alert',
            'title' => 'سلامت فراست بازیابی شد',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://alerts.example.test/farast'
            && $request->hasHeader('Authorization', 'Bearer webhook-secret')
            && ($request['severity'] ?? null) === 'critical');

    }

    public function test_alerts_are_not_broadcast_when_no_explicit_recipients_are_configured(): void
    {
        config([
            'observability.alerts.user_ids' => [],
            'observability.webhook.url' => 'http://untrusted.example.test/hook',
        ]);
        Http::fake();
        $observability = \Mockery::mock(FarastObservabilityService::class);
        $observability->shouldReceive('snapshot')->once()->andReturn([
            'status' => 'warning',
            'components' => ['queue' => ['status' => 'warning']],
        ]);
        app()->instance(FarastObservabilityService::class, $observability);

        $this->assertSame(0, Artisan::call('farast:observability:check'));
        $this->assertDatabaseCount('farast_notifications', 0);
        Http::assertNothingSent();

    }
}
