<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StoreOrderItem extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('store_order_item_snapshots_are_immutable');
        });

        static::deleting(function (): never {
            throw new LogicException('store_order_item_snapshots_cannot_be_deleted');
        });
    }

    protected $fillable = ['order_id','product_id','title_snapshot','sku_snapshot','quantity','unit_price_rials','discount_rials','tax_rials','total_rials','product_snapshot'];
    protected $casts = ['quantity'=>'integer','unit_price_rials'=>'integer','discount_rials'=>'integer','tax_rials'=>'integer','total_rials'=>'integer','product_snapshot'=>'array'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
}
