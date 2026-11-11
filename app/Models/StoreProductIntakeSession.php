<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class StoreProductIntakeSession extends Model
{
    public const STATUS_SELECTING_FILES = 'selecting_files';
    public const STATUS_FILES_UPLOADED = 'files_uploaded';
    public const STATUS_DETAILS = 'details';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'product_id', 'product_type', 'status',
        'antivirus_requested', 'metadata', 'expires_at', 'submitted_at',
    ];

    protected $casts = [
        'antivirus_requested' => 'boolean',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoreProductIntakeFile::class, 'intake_session_id');
    }

    public function isUsable(): bool
    {
        return ! in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_REJECTED, self::STATUS_EXPIRED], true)
            && $this->expires_at?->isFuture();
    }
}
