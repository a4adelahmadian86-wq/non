<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastUsageReservation extends Model
{
    protected $table = 'farast_usage_reservations';
    protected $fillable = [
        'reservation_id','idempotency_key','actor_id','organization_id','project_id','document_id',
        'entitlement_id','pricing_policy_version_id','capability','quantity','unit','mode','status',
        'expires_at','committed_at','released_at','usage_event_id','metadata',
    ];
    protected function casts(): array
    {
        return ['quantity'=>'decimal:6','expires_at'=>'datetime','committed_at'=>'datetime','released_at'=>'datetime','metadata'=>'array'];
    }
}