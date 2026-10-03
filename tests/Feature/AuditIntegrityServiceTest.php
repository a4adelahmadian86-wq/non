<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Services\AuditIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }
}