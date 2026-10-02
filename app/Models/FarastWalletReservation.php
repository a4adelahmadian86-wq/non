<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastWalletReservation extends Model
{
    protected $table = 'farast_wallet_reservations';
    protected $fillable = [
        'reservation_id','idempotency_key','wallet_id','charge_id','amount','currency','status',
        'expires_at','committed_at','released_at','metadata',
    ];
    protected function casts(): array { return ['amount'=>'integer','expires_at'=>'datetime','committed_at'=>'datetime','released_at'=>'datetime','metadata'=>'array']; }
}