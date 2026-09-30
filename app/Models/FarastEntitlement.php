<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastEntitlement extends Model { protected $table='farast_entitlements'; protected $fillable=['user_id','project_id','capability_code','mode','status','quantity','used_quantity','starts_at','ends_at','metadata']; protected function casts():array{return ['quantity'=>'integer','used_quantity'=>'integer','starts_at'=>'datetime','ends_at'=>'datetime','metadata'=>'array'];} }