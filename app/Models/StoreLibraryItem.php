<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StoreLibraryItem extends Model
{
    protected static function booted(): void
    {
        static::updating(function (StoreLibraryItem $item): void {
            if ($item->isDirty(['user_id', 'product_id', 'order_id', 'order_item_id', 'product_file_id', 'license_code', 'granted_at'])) {
                throw new LogicException('store_library_entitlement_identity_is_immutable');
            }
        });

        static::deleting(function (StoreLibraryItem $item): void {
            if (
                $item->order_id !== null
                || $item->order_item_id !== null
                || $item->downloads()->exists()
                || StoreCourseEnrollment::query()->where('library_item_id', $item->id)->exists()
            ) {
                throw new LogicException('purchased_library_entitlements_must_be_revoked_not_deleted');
            }
        });
    }

    protected $fillable = ['user_id','product_id','order_id','order_item_id','product_file_id','license_code','granted_at','revoked_at'];
    protected $casts = ['granted_at'=>'datetime','revoked_at'=>'datetime'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(StoreOrderItem::class, 'order_item_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'product_file_id'); }
    public function downloads(): HasMany { return $this->hasMany(StoreDownload::class, 'library_item_id'); }
}
