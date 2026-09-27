<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    protected $table = 'ai_feedback';
    protected $fillable = [
        'user_id','document_id','ai_interaction_id','type','rating','category',
        'original_text','corrected_text','note','context'
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'rating' => 'integer'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function document(): BelongsTo { return $this->belongsTo(TypingDocument::class, 'document_id'); }
    public function interaction(): BelongsTo { return $this->belongsTo(AiInteraction::class, 'ai_interaction_id'); }
}
