<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['order_id','gateway','authority','transaction_id','amount_rials','status','gateway_response','paid_at'];
    protected $casts = ['amount_rials'=>'integer','gateway_response'=>'array','paid_at'=>'datetime'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
