<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarastTool extends Model
{
    protected $table='farast_tools';
    protected $fillable=['code','name','capability_id','provider_id','status','risk_level','metadata','stable_id','input_schema','output_schema','permissions','entitlement_policy','usage_meter','reversible','transactional','audit_enabled','timeout_seconds','retry_count','provenance'];
    protected function casts():array{return ['metadata'=>'array','input_schema'=>'array','output_schema'=>'array','permissions'=>'array','entitlement_policy'=>'array','reversible'=>'boolean','transactional'=>'boolean','audit_enabled'=>'boolean','provenance'=>'array'];}
    public function capability():BelongsTo{return $this->belongsTo(FarastCapability::class,'capability_id');}
    public function provider():BelongsTo{return $this->belongsTo(FarastProvider::class,'provider_id');}
    public function versions():HasMany{return $this->hasMany(FarastToolVersion::class,'tool_id');}
    public function applications():BelongsToMany{return $this->belongsToMany(FarastApplication::class,'farast_tool_applications','tool_id','application_id');}
}