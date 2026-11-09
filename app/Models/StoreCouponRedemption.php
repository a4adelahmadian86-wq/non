<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'order_id',
        'status',
        'discount_rials',
        'expires_at',
        'redeemed_at',
        'released_at',
    ];

    protected $casts = [
        'discount_rials' => 'integer',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(StoreCoupon::class, 'coupon_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
