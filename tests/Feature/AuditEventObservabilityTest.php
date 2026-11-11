<?php

namespace Tests\Feature;

use App\Services\AuditEventService;
use App\Services\AuditIntegrityService;
use App\Services\FarastTraceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditEventObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_audit_ledger_records_trace_and_redacts_sensitive_metadata(): void
    {
        $request = Request::create('/admin/settings', 'POST');
        $request->attributes->set('farast.request_id', 'request-ledger-123');
        $request->attributes->set('farast.correlation_id', 'correlation-ledger-456');
        app()->instance('request', $request);

        $event = app(AuditEventService::class)->record(
            'settings.updated',
            7,
            null,
            'settings',
            1,
            [
                'api_token' => 'must-not-persist',
                'nested' => ['password' => 'secret'],
                'callback' => 'https://example.test/callback?token=must-not-persist&state=ok',
            ],
            'admin'
        );

        $this->assertSame('request-ledger-123', $event->payload_meta['request_id']);
        $this->assertSame('correlation-ledger-456', $event->payload_meta['correlation_id']);
        $this->assertSame('[REDACTED]', $event->payload_meta['api_token']);
        $this->assertSame('[REDACTED]', $event->payload_meta['nested']['password']);
        $this->assertSame('https://example.test/callback?token=[REDACTED]&state=ok', $event->payload_meta['callback']);
        $this->assertTrue(app(AuditIntegrityService::class)->verify()['ok']);
    }

    public function test_canonical_audit_ledger_prefers_active_job_trace_over_stale_request_attributes(): void
    {
        $request = Request::create('/admin/settings', 'POST');
        $request->attributes->set('farast.request_id', 'stale-request');
        $request->attributes->set('farast.correlation_id', 'stale-correlation');
        app()->instance('request', $request);
        app(FarastTraceContext::class)->set('job-request-123', 'job-correlation-456');

        $event = app(AuditEventService::class)->record('job.completed', 7);

        $this->assertSame('job-request-123', $event->payload_meta['request_id']);
        $this->assertSame('job-correlation-456', $event->payload_meta['correlation_id']);
        $this->assertTrue(app(AuditIntegrityService::class)->verify()['ok']);
    }
}
