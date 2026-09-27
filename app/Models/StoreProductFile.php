<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductFile extends Model
{
    protected $fillable = ['product_id','version','disk','path','original_name','mime','size_bytes','sha256','is_primary','is_active'];
    protected $casts = ['size_bytes'=>'integer','is_primary'=>'boolean','is_active'=>'boolean'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
}
