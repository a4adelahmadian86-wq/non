<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoreTag extends Model
{
    protected $fillable = ['name','slug'];
    public function products(): BelongsToMany { return $this->belongsToMany(StoreProduct::class, 'store_product_tag', 'tag_id', 'product_id'); }
}
