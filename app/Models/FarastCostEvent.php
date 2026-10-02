<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastCostEvent extends Model
{
    protected $table = 'farast_cost_events';
    protected $fillable = [
        'event_id','idempotency_key','actor_id','organization_id','project_id','tool_id','tool_version_id',
        'capability','provider','unit','quantity','cost_amount','currency','duration_ms','input_bytes',
        'output_bytes','metadata',
    ];
    protected function casts(): array { return ['quantity'=>'decimal:6','cost_amount'=>'integer','metadata'=>'array']; }
}