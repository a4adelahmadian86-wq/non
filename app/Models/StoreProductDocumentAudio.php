<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProductDocumentAudio extends Model
{
    protected $fillable = ['document_id', 'storage_disk', 'storage_path', 'mime', 'duration_ms', 'state', 'voice_profile', 'alignment_metadata', 'generated_at'];
    protected $casts = ['duration_ms' => 'integer', 'alignment_metadata' => 'array', 'generated_at' => 'datetime'];

    public function document(): BelongsTo { return $this->belongsTo(StoreProductDocument::class, 'document_id'); }
    public function segments(): HasMany { return $this->hasMany(StoreProductDocumentAudioSegment::class, 'audio_id')->orderBy('sequence'); }
}
