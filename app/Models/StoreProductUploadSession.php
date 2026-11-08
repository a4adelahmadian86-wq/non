<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProductUploadSession extends Model
{
    protected $fillable = [
        'product_id', 'token_hash', 'state', 'original_name', 'detected_mime',
        'size_bytes', 'sha256', 'quarantine_disk', 'quarantine_path', 'inspection',
        'security_report', 'expires_at', 'confirmed_at', 'created_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'inspection' => 'array',
        'security_report' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StoreProductUploadEvent::class, 'upload_session_id')->orderBy('id');
    }
}
