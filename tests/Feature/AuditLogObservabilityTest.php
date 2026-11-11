<?php

namespace Tests\Feature;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AuditLogObservabilityTest extends TestCase
{
    public function test_audit_metadata_contains_trace_ids_and_redacts_sensitive_values(): void
    {
        $request = Request::create('/admin/settings', 'POST');
        $request->attributes->set('farast.request_id', 'request-123');
        $request->attributes->set('farast.correlation_id', 'correlation-456');
        app()->instance('request', $request);

        $query = Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($query);
        $query->shouldReceive('insert')->once()->with(Mockery::on(function (array $row): bool {
            $meta = json_decode($row['meta'], true);

            return ($meta['request_id'] ?? null) === 'request-123'
                && ($meta['correlation_id'] ?? null) === 'correlation-456'
                && ($meta['api_token'] ?? null) === '[REDACTED]'
                && ($meta['nested']['password'] ?? null) === '[REDACTED]'
                && ($meta['callback'] ?? null) === 'https://example.test/callback?token=[REDACTED]&state=ok';
        }));

        app(AuditLogService::class)->record(7, 'settings.updated', '127.0.0.1', [
            'api_token' => 'never-store-this',
            'nested' => ['password' => 'secret-value'],
            'callback' => 'https://example.test/callback?token=secret-value&state=ok',
        ]);
    }

    public function test_audit_uses_active_job_trace_instead_of_stale_request_attributes(): void
    {
        $request = Request::create('/admin/settings', 'POST');
        $request->attributes->set('farast.request_id', 'stale-request');
        $request->attributes->set('farast.correlation_id', 'stale-correlation');
        app()->instance('request', $request);
        app(FarastTraceContext::class)->set('job-request-123', 'job-correlation-456');

        $query = Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($query);
        $query->shouldReceive('insert')->once()->with(Mockery::on(function (array $row): bool {
            $meta = json_decode($row['meta'], true);

            return ($meta['request_id'] ?? null) === 'job-request-123'
                && ($meta['correlation_id'] ?? null) === 'job-correlation-456';
        }));

        app(AuditLogService::class)->record(7, 'job.action', null);
    }
}
