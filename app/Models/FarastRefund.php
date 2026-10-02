<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastRefund extends Model
{
    protected $table = 'farast_refunds';
    protected $fillable = [
        'refund_id','idempotency_key','charge_id','payment_id','actor_id','amount','currency',
        'status','reason','metadata',
    ];
    protected function casts(): array { return ['amount'=>'integer','metadata'=>'array']; }
}