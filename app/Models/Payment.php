<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class Payment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (blank($payment->idempotency_key)) {
                $payment->idempotency_key = (string) Str::uuid();
            }
        });

        static::updating(function (Payment $payment): void {
            $wasSettled = $payment->getOriginal('paid_at') !== null || $payment->getOriginal('status') === 'paid';
            if (! $wasSettled) {
                return;
            }

            if ($payment->isDirty(['order_id', 'gateway', 'authority', 'transaction_id', 'amount_rials', 'status', 'paid_at', 'idempotency_key'])) {
                throw new LogicException('settled_payment_financial_record_is_immutable');
            }
        });

        static::deleting(function (Payment $payment): void {
            if ($payment->getOriginal('paid_at') !== null || $payment->getOriginal('status') === 'paid') {
                throw new LogicException('settled_payment_cannot_be_deleted');
            }
        });
    }

    protected $fillable = ['order_id','gateway','authority','transaction_id','amount_rials','status','gateway_response','paid_at','idempotency_key'];
    protected $casts = ['amount_rials'=>'integer','gateway_response'=>'array','paid_at'=>'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
