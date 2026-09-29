<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInteraction extends Model
{
    protected $fillable = [
        'user_id','document_id','provider','model','operation','request_id',
        'provider_interaction_id','source_hash','prompt_hash','latency_ms',
        'input_bytes','input_tokens','output_tokens','total_tokens','estimated_cost','output_bytes','input_meta','output_meta','status','error_message'
    ];

    protected function casts(): array
    {
        return ['input_meta' => 'array', 'output_meta' => 'array', 'estimated_cost' => 'decimal:6'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function document(): BelongsTo { return $this->belongsTo(TypingDocument::class, 'document_id'); }
}
