<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastCapability extends Model { protected $table='farast_capabilities'; protected $fillable=['code','name','billing_mode','unit','status','metadata']; protected function casts():array{return ['metadata'=>'array'];} }