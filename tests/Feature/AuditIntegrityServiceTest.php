<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Services\AuditIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_chain_is_verifiable():void
    {
        $payload=['event_type'=>'test.one','user_id'=>null,'project_id'=>null,'aggregate_type'=>null,'aggregate_id'=>null,'source'=>'test','payload_meta'=>['x'=>1]];
        FarastAuditEvent::create(['event_id'=>(string)Str::uuid(),'event_type'=>'test.one','source'=>'test','payload_meta'=>['x'=>1],'payload_checksum'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))]);
        $result=(new AuditIntegrityService())->verify();
        $this->assertTrue($result['ok']);$this->assertSame(1,$result['checked']);
        $this->assertSame(0, Artisan::call('farast:audit-ledger:verify'));
    }

    public function test_audit_append_normalizes_invalid_utf8_before_checksumming(): void
    {
        $event = app(\App\Services\AuditEventService::class)->record(
            'test.invalid_utf8',
            payloadMeta: ['label' => "bad\xB1"],
            source: 'test'
        );

        $this->assertSame("bad\u{FFFD}", $event->payload_meta['label']);
        $result = (new AuditIntegrityService())->verify();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['checked']);
    }

    public function test_versioned_checksum_detects_event_identity_and_timestamp_tampering(): void
    {
        $service = app(\App\Services\AuditEventService::class);
        $first = $service->record('test.identity');
        $second = $service->record('test.timestamp');

        DB::table('farast_audit_events')
            ->where('id', $first->id)
            ->update(['event_id' => (string) Str::uuid()]);
        DB::table('farast_audit_events')
            ->where('id', $second->id)
            ->update(['created_at' => now()->subDay()->format('Y-m-d H:i:s')]);

        $result = (new AuditIntegrityService())->verify();

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['error_count']);
    }

    public function test_integrity_verification_caps_error_samples_but_reports_total_count(): void
    {
        for ($index = 0; $index < 101; $index++) {
            FarastAuditEvent::create([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'test.tampered',
                'source' => 'test',
                'payload_meta' => ['index' => $index],
                'payload_checksum' => str_repeat('0', 64),
                'previous_checksum' => null,
            ]);
        }

        $result = (new AuditIntegrityService())->verify();

        $this->assertFalse($result['ok']);
        $this->assertSame(101, $result['error_count']);
        $this->assertCount(100, $result['errors']);
        $this->assertTrue($result['errors_truncated']);
    }

    public function test_audit_ledger_command_fails_and_emits_safe_failure_when_chain_is_tampered(): void
    {
        $payload = [
            'event_type' => 'test.tampered',
            'user_id' => null,
            'project_id' => null,
            'aggregate_type' => null,
            'aggregate_id' => null,
            'source' => 'test',
            'payload_meta' => ['x' => 1],
        ];

        $event = FarastAuditEvent::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'test.tampered',
            'source' => 'test',
            'payload_meta' => ['x' => 1],
            'payload_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        $event->update(['payload_meta' => ['x' => 2]]);

        $this->assertSame(1, Artisan::call('farast:audit-ledger:verify'));
    }
}
