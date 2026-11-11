<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (blank($payment->idempotency_key)) {
                $payment->idempotency_key = (string) Str::uuid();
            }
        });
    }

    protected $fillable = ['order_id','gateway','authority','transaction_id','amount_rials','status','gateway_response','paid_at','idempotency_key'];
    protected $casts = ['amount_rials'=>'integer','gateway_response'=>'array','paid_at'=>'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
