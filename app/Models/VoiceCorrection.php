<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCorrection extends Model
{
    protected $fillable = [
        'user_id','locale','provider','wrong_text','correct_text','context_hash',
        'occurrences','confirmations','confidence','verified','global','last_seen_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'verified' => 'boolean',
        'global' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
