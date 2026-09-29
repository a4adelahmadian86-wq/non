<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Team extends Model {protected $fillable=['organization_id','name','slug','supervisor_id','settings'];protected $casts=['settings'=>'array'];public function organization():BelongsTo{return $this->belongsTo(Organization::class);}public function supervisor():BelongsTo{return $this->belongsTo(User::class,'supervisor_id');}public function members():BelongsToMany{return $this->belongsToMany(User::class)->withPivot('team_role')->withTimestamps();}}
