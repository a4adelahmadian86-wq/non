<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductPreview extends Model
{
    protected $fillable = ['product_id','document_id','source_sha256','disk','path','mime','page_number','kind','access_level','watermarked','is_active','sort_order'];
    protected $casts = ['page_number'=>'integer','watermarked'=>'boolean','is_active'=>'boolean','sort_order'=>'integer'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function document(): BelongsTo { return $this->belongsTo(StoreDocumentRecord::class, 'document_id'); }
}
