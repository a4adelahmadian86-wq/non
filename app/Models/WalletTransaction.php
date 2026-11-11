<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = ['wallet_id','type','amount_rials','balance_before','balance_after','reference_type','reference_id','description','idempotency_key'];
    protected $casts = ['amount_rials'=>'integer','balance_before'=>'integer','balance_after'=>'integer'];
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            throw new \LogicException('wallet_transaction_is_immutable');
        });

        static::deleting(function (self $transaction): void {
            throw new \LogicException('wallet_transaction_is_immutable');
        });
    }
}
