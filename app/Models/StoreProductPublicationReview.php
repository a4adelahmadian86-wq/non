<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductPublicationReview extends Model
{
    public const STATUS_PENDING_AI = 'pending_ai_review';
    public const STATUS_PENDING_MANAGER = 'pending_manager';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'product_id', 'intake_session_id', 'submitted_by', 'status', 'ai_verdict',
        'ai_score', 'ai_review_json', 'manager_reviewed_by', 'manager_reviewed_at',
        'manager_notes', 'published_at',
    ];

    protected $casts = [
        'ai_score' => 'integer',
        'ai_review_json' => 'array',
        'manager_reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(StoreProductIntakeSession::class, 'intake_session_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function managerReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_reviewed_by');
    }
}
