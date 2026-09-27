<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCart extends Model
{
    protected $fillable = ['user_id','session_token','expires_at'];
    protected $casts = ['expires_at'=>'datetime'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(StoreCartItem::class, 'cart_id')->with('product'); }
}
