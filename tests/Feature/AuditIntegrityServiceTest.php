<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Services\AuditIntegrityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    public function test_canonical_payload_checksum_survives_json_object_key_reordering(): void
    {
        $meta = [
            'zeta' => 1,
            'alpha' => ['y' => 2, 'beta' => 3],
        ];

        $event = app(\App\Services\AuditEventService::class)->record(
            'test.canonical_json',
            null,
            null,
            'test',
            null,
            $meta,
            'test'
        );

        $this->assertNotEmpty($event->payload_canonical);

        // Simulate a JSON database returning object keys in a different order.
        DB::table('farast_audit_events')
            ->where('id', $event->id)
            ->update([
                'payload_meta' => json_encode([
                    'alpha' => ['beta' => 3, 'y' => 2],
                    'zeta' => 1,
                ]),
            ]);

        $this->assertTrue(app(AuditIntegrityService::class)->verify()['ok']);
    }


}
