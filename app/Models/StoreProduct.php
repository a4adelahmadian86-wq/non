<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProduct extends Model
{
    protected $fillable = ['category_id','seller_id','title','slug','sku','type','short_description','description','price_rials','compare_at_price_rials','tax_class','status','published_at','featured','sort_order','seo_title','seo_description','cover_path','preview_policy','preview_pages','download_policy','license_type','version','metadata'];
    protected $casts = ['published_at'=>'datetime','featured'=>'boolean','metadata'=>'array','price_rials'=>'integer','compare_at_price_rials'=>'integer','preview_pages'=>'integer'];

    public function category(): BelongsTo { return $this->belongsTo(StoreCategory::class, 'category_id'); }
    public function seller(): BelongsTo { return $this->belongsTo(User::class, 'seller_id'); }
    public function files(): HasMany { return $this->hasMany(StoreProductFile::class, 'product_id'); }
    public function previews(): HasMany { return $this->hasMany(StoreProductPreview::class, 'product_id')->orderBy('sort_order'); }
    public function images(): HasMany { return $this->hasMany(StoreProductImage::class, 'product_id')->orderBy('sort_order'); }
    public function tags(): BelongsToMany { return $this->belongsToMany(StoreTag::class, 'store_product_tag', 'product_id', 'tag_id'); }
    public function related(): BelongsToMany { return $this->belongsToMany(self::class, 'store_product_related', 'product_id', 'related_product_id'); }
    public function reviews(): HasMany { return $this->hasMany(StoreProductReview::class, 'product_id'); }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
