<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastUsageCounter extends Model
{
    protected $table = 'farast_usage_counters';
    protected $fillable = ['scope_key','capability','unit','quantity','period_start','period_end'];
    protected function casts(): array { return ['quantity'=>'decimal:6','period_start'=>'datetime','period_end'=>'datetime']; }
}
