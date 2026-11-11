<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class StoreProductIntakeFile extends Model
{
    protected $fillable = [
        'intake_session_id', 'user_id', 'status', 'staging_disk', 'staging_path',
        'managed_disk', 'managed_path', 'managed_file_id', 'product_file_id',
        'original_name', 'mime', 'size_bytes', 'sha256', 'inspection_json',
        'is_primary', 'expires_at', 'uploaded_at',
    ];

    protected $casts = [
        'inspection_json' => 'array',
        'size_bytes' => 'integer',
        'is_primary' => 'boolean',
        'expires_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StoreProductIntakeSession::class, 'intake_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function productFile(): BelongsTo
    {
        return $this->belongsTo(StoreProductFile::class, 'product_file_id');
    }
}
