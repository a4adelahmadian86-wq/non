<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentAudioSegment extends Model
{
    protected $fillable = ['audio_id', 'document_block_id', 'sequence', 'start_ms', 'end_ms', 'visual_locator'];
    protected $casts = ['sequence' => 'integer', 'start_ms' => 'integer', 'end_ms' => 'integer', 'visual_locator' => 'array'];

    public function audio(): BelongsTo { return $this->belongsTo(StoreProductDocumentAudio::class, 'audio_id'); }
    public function block(): BelongsTo { return $this->belongsTo(StoreProductDocumentBlock::class, 'document_block_id'); }
}
