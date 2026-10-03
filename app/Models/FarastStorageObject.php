<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FarastStorageObject extends Model
{
    protected $table='farast_storage_objects';
    protected $fillable=['object_id','user_id','project_id','document_id','disk','path','mime','size','checksum','status','classification','version','revision','provenance'];
    protected static function booted():void{static::creating(fn($m)=>$m->object_id??=(string)Str::uuid());}
    protected function casts():array{return ['provenance'=>'array'];}
}