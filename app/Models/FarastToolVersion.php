<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarastToolVersion extends Model
{
    protected $table='farast_tool_versions';
    protected $fillable=['tool_id','version','status','evaluation_status','review_status','quality_score','configuration','last_verified_at','stable_id'];
    protected function casts():array{return ['configuration'=>'array','quality_score'=>'float','last_verified_at'=>'datetime'];}
    public function tool():BelongsTo{return $this->belongsTo(FarastTool::class,'tool_id');}
}