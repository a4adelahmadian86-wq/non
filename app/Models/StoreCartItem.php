<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCartItem extends Model
{
    protected $fillable = ['cart_id','product_id','quantity','price_snapshot_rials'];
    protected $casts = ['quantity'=>'integer','price_snapshot_rials'=>'integer'];
    public function cart(): BelongsTo { return $this->belongsTo(StoreCart::class, 'cart_id'); }
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
}
