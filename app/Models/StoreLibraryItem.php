<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreLibraryItem extends Model
{
    protected $fillable = ['user_id','product_id','order_id','order_item_id','product_file_id','license_code','granted_at','revoked_at'];
    protected $casts = ['granted_at'=>'datetime','revoked_at'=>'datetime'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(StoreOrderItem::class, 'order_item_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'product_file_id'); }
    public function downloads(): HasMany { return $this->hasMany(StoreDownload::class, 'library_item_id'); }
}
