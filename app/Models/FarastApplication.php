<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class FarastApplication extends Model {
 protected $table='farast_applications'; protected $fillable=['code','name','status','metadata']; protected function casts():array{return ['metadata'=>'array'];}
 public function tools():BelongsToMany{return $this->belongsToMany(FarastTool::class,'farast_tool_applications','application_id','tool_id');}
}