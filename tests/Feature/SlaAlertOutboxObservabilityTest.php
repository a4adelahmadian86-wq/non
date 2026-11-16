<?php

namespace Tests\Feature;

use App\Services\FarastObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SlaAlertOutboxObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.product_supervisor.url' => '',
            'services.product_supervisor.token' => '',
            'services.document_audio.url' => '',
            'services.document_audio.token' => '',
        ]);
    }

    public function test_dashboard_reports_sla_outbox_backlog_retries_and_terminal_failures_without_error_payloads(): void
    {
        $now = now();
        DB::table('support_sla_alert_outbox')->insert([
            [
                'ticket_id' => 9001,
                'kind' => 'first_response',
                'event' => 'breached',
                'event_identity' => 'sla:first_response:breached:pending',
                'idempotency_key' => 'observability:sla:pending',
                'status' => 'pending',
                'attempts' => 0,
                'locked_at' => null,
                'lease_token' => null,
                'delivered_at' => null,
                'last_error' => null,
                'available_at' => $now->copy()->subMinutes(2),
                'created_at' => $now->copy()->subMinutes(3),
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 9002,
                'kind' => 'first_response',
                'event' => 'breached',
                'event_identity' => 'sla:first_response:breached:processing-stale',
                'idempotency_key' => 'observability:sla:processing-stale',
                'status' => 'processing',
                'attempts' => 1,
                'available_at' => $now->copy()->subMinutes(16),
                'locked_at' => $now->copy()->subMinutes(16),
                'lease_token' => '00000000-0000-4000-8000-000000000001',
                'delivered_at' => null,
                'last_error' => null,
                'created_at' => $now->copy()->subMinutes(16),
                'updated_at' => $now->copy()->subMinutes(16),
            ],
            [
                'ticket_id' => 9003,
                'kind' => 'resolution',
                'event' => 'warning',
                'event_identity' => 'sla:resolution:warning:processing-fresh',
                'idempotency_key' => 'observability:sla:processing-fresh',
                'status' => 'processing',
                'attempts' => 1,
                'available_at' => $now,
                'locked_at' => $now->copy()->subMinute(),
                'lease_token' => '00000000-0000-4000-8000-000000000002',
                'delivered_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 9004,
                'kind' => 'resolution',
                'event' => 'warning',
                'event_identity' => 'sla:resolution:warning:retryable',
                'idempotency_key' => 'observability:sla:retryable',
                'status' => 'failed',
                'attempts' => 2,
                'locked_at' => null,
                'lease_token' => null,
                'delivered_at' => null,
                'available_at' => $now->copy()->addMinutes(3),
                'last_error' => 'provider-secret-and-recipient-data-must-not-leak',
                'created_at' => $now->copy()->subMinutes(4),
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 9005,
                'kind' => 'first_response',
                'event' => 'breached',
                'event_identity' => 'sla:first_response:breached:terminal',
                'idempotency_key' => 'observability:sla:terminal',
                'status' => 'failed',
                'attempts' => 8,
                'locked_at' => null,
                'lease_token' => null,
                'delivered_at' => null,
                'available_at' => $now->copy()->subMinute(),
                'last_error' => 'terminal-provider-secret-must-not-leak',
                'created_at' => $now->copy()->subMinutes(20),
                'updated_at' => $now,
            ],
        ]);

        $snapshot = app(FarastObservabilityService::class)->snapshot();
        $component = $snapshot['components']['sla_alerts'];

        $this->assertSame('critical', $component['status']);
        $this->assertTrue($component['available']);
        $this->assertSame(1, $component['pending']);
        $this->assertSame(2, $component['processing']);
        $this->assertSame(1, $component['retryable_failed']);
        $this->assertSame(1, $component['terminal_failed']);
        $this->assertSame(1, $component['stale_processing']);
        $this->assertSame(2, $component['due']);
        $this->assertSame(2, $component['oldest_due_minutes']);
        $this->assertArrayNotHasKey('last_error', $component);
        $this->assertStringNotContainsString('provider-secret', json_encode($component));
    }

    public function test_dashboard_does_not_report_sla_outbox_healthy_when_its_table_is_missing(): void
    {
        Schema::dropIfExists('support_sla_alert_outbox');

        $snapshot = app(FarastObservabilityService::class)->snapshot();
        $component = $snapshot['components']['sla_alerts'];

        $this->assertSame('warning', $component['status']);
        $this->assertFalse($component['available']);
        $this->assertNull($component['pending']);
    }
}
