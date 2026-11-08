<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductIngestion extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','product_id','user_id','status','staging_disk','staging_path','original_name',
        'mime','size_bytes','sha256','inspection_json','is_primary','expires_at','confirmed_at',
    ];

    protected $casts = [
        'inspection_json' => 'array',
        'size_bytes' => 'integer',
        'is_primary' => 'boolean',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
