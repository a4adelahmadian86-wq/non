<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WeeklyFreePage extends Model {protected $fillable=['user_id','week_start','pages'];protected function casts():array{return ['week_start'=>'date','pages'=>'integer'];}}
