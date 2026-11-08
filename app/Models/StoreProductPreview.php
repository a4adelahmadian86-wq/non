<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductPreview extends Model
{
    protected $fillable = ['product_id','disk','path','mime','page_number','source_page_count','included_page_count','kind','access_level','watermarked','is_active','sort_order','audio_disk','audio_path','audio_mime','audio_timing'];
    protected $casts = ['page_number'=>'integer','source_page_count'=>'integer','included_page_count'=>'integer','watermarked'=>'boolean','is_active'=>'boolean','sort_order'=>'integer','audio_timing'=>'array'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
}
