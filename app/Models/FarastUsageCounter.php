<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastUsageCounter extends Model
{
    protected $table='farast_usage_counters';
    protected $fillable=['user_id','metric','used','period_limit','period_start','period_end'];
    protected function casts():array{return ['used'=>'integer','period_limit'=>'integer','period_start'=>'date','period_end'=>'date'];}
}
