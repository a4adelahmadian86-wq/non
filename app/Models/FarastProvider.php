<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FarastProvider extends Model { protected $table='farast_providers'; protected $fillable=['code','name','kind','status','metadata']; protected function casts():array{return ['metadata'=>'array'];} }