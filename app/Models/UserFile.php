<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFile extends Model
{
    protected $fillable = [
        'user_id', 'original_name', 'path', 'disk', 'mime', 'size_bytes',
        'page_count', 'estimated_price_rials', 'status', 'remote_path',
        'uploaded_at', 'local_expires_at', 'remote_expires_at', 'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'estimated_price_rials' => 'integer',
            'uploaded_at' => 'datetime',
            'local_expires_at' => 'datetime',
            'remote_expires_at' => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
