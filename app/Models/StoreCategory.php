<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCategory extends Model
{
    protected $fillable = ['parent_id','name','slug','description','icon','cover_path','seo_title','seo_description','is_active','sort_order'];
    protected $casts = ['is_active'=>'boolean','sort_order'=>'integer'];

    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order'); }
    public function products(): HasMany { return $this->hasMany(StoreProduct::class, 'category_id'); }
}
