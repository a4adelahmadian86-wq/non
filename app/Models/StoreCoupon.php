<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreCoupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'min_subtotal_rials', 'max_uses', 'used_count',
        'is_active', 'starts_at', 'ends_at', 'description',
    ];

    protected $casts = [
        'value' => 'integer',
        'min_subtotal_rials' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $coupon): void {
            $coupon->code = mb_strtoupper(trim((string) $coupon->code));
            $coupon->description = filled($coupon->description)
                ? trim((string) $coupon->description)
                : null;
        });
    }
}
