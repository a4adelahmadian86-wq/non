<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductImage extends Model
{
    protected $fillable = ['product_id','path','alt','kind','sort_order','is_active'];
    protected $casts = ['sort_order'=>'integer','is_active'=>'boolean'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
}
