<?php

namespace App\Services;

use App\Models\FarastAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditEventService
{
    public function record(string $eventType,?int $userId=null,?int $projectId=null,?string $aggregateType=null,?int $aggregateId=null,array $payloadMeta=[],string $source='application'):FarastAuditEvent
    {
        return DB::transaction(function()use($eventType,$userId,$projectId,$aggregateType,$aggregateId,$payloadMeta,$source){
            $previous=FarastAuditEvent::query()->lockForUpdate()->latest('id')->value('payload_checksum');
            $payload=['event_type'=>$eventType,'user_id'=>$userId,'project_id'=>$projectId,'aggregate_type'=>$aggregateType,'aggregate_id'=>$aggregateId,'source'=>$source,'payload_meta'=>$payloadMeta];
            return FarastAuditEvent::create(['event_id'=>(string)Str::uuid(),'user_id'=>$userId,'project_id'=>$projectId,'event_type'=>$eventType,'aggregate_type'=>$aggregateType,'aggregate_id'=>$aggregateId,'source'=>$source,'payload_meta'=>$payloadMeta,'payload_checksum'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'previous_checksum'=>$previous]);
        });
    }
}