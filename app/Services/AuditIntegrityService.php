<?php

namespace App\Services;

use App\Models\FarastAuditEvent;

class AuditIntegrityService
{
    public function verify():array
    {
        $previous=null;$checked=0;$errors=[];
        FarastAuditEvent::query()->orderBy('id')->chunkById(500,function($rows)use(&$previous,&$checked,&$errors){
            foreach($rows as $row){
                $payload=['event_type'=>$row->event_type,'user_id'=>$row->user_id,'project_id'=>$row->project_id,'aggregate_type'=>$row->aggregate_type,'aggregate_id'=>$row->aggregate_id,'source'=>$row->source,'payload_meta'=>$row->payload_meta];
                $expected=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                if($row->previous_checksum!==$previous||!hash_equals((string)$row->payload_checksum,$expected))$errors[]=['id'=>$row->id,'event_id'=>$row->event_id];
                $previous=$row->payload_checksum;$checked++;
            }
        });
        return ['ok'=>empty($errors),'checked'=>$checked,'errors'=>$errors];
    }
}