<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FarastToolExecution extends Model
{
    protected $table='farast_tool_executions';
    protected $fillable=['execution_id','tool_id','tool_version_id','actor_id','organization_id','project_id','document_id','capability','status','idempotency_key','correlation_id','request_id','transaction_id','input_meta','output_meta','provenance','output_checksum','error_code','error_message','usage_event_id','audit_event_id','started_at','finished_at'];
    protected static function booted():void{static::creating(fn($m)=>$m->execution_id??=(string)Str::uuid());}
    protected function casts():array{return ['input_meta'=>'array','output_meta'=>'array','provenance'=>'array','started_at'=>'datetime','finished_at'=>'datetime'];}
}