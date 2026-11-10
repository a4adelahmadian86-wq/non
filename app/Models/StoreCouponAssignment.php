<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCouponAssignment extends Model
{
    protected $fillable = [
        'coupon_id', 'campaign_id', 'user_id', 'product_id', 'status', 'score', 'reason_codes',
        'offered_at', 'expires_at', 'redeemed_at', 'redeemed_order_id', 'notification_id',
    ];

    protected $casts = [
        'score' => 'integer',
        'reason_codes' => 'array',
        'offered_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(StoreCoupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function redeemedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'redeemed_order_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(FarastNotification::class, 'notification_id');
    }
}
