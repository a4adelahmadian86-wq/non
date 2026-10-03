<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastHumanTask extends Model
{
    protected $table='farast_human_tasks';
    protected $fillable=['task_id','capability','project_id','requester_id','specialist_id','sla','input','output','qa','acceptance','billing','provenance','status','correlation_id'];
    protected function casts():array{return ['sla'=>'array','input'=>'array','output'=>'array','qa'=>'array','acceptance'=>'array','billing'=>'array','provenance'=>'array'];}
}