<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductPublicationReview extends Model
{
    protected $fillable = [
        'product_id', 'requested_by', 'manager_approved_by', 'status', 'verdict', 'score',
        'review_id', 'model', 'snapshot_hash', 'reviewed_snapshot_hash', 'snapshot_json',
        'findings_json', 'corrections_json', 'summary', 'auto_publish', 'attempts',
        'failure_code', 'reviewed_at', 'manager_approved_at', 'published_at',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'findings_json' => 'array',
        'corrections_json' => 'array',
        'auto_publish' => 'boolean',
        'score' => 'integer',
        'attempts' => 'integer',
        'reviewed_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }
}
