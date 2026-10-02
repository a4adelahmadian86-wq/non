<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastUsageEvent extends Model
{
    protected $table = 'farast_usage_events';
    protected $fillable = [
        'event_id','idempotency_key','actor_id','organization_id','project_id','document_id',
        'tool_id','tool_version_id','capability','quantity','unit','occurred_at',
        'entitlement_id','pricing_policy_version_id','cost_metadata','metadata','checksum',
    ];
    protected function casts(): array
    {
        return ['quantity'=>'decimal:6','occurred_at'=>'datetime','cost_metadata'=>'array','metadata'=>'array'];
    }
    protected static function booted(): void
    {
        static::updating(function () { throw new \LogicException('usage_event_is_immutable'); });
        static::deleting(function () { throw new \LogicException('usage_event_is_immutable'); });
    }

}