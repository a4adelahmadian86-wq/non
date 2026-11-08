<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreDocumentAudioTrack extends Model
{
    protected $fillable = ['document_id','source_sha256','disk','path','mime','duration_ms','status','provider','generated_at'];
    protected $casts = ['duration_ms'=>'integer','generated_at'=>'datetime'];
    public function document(): BelongsTo { return $this->belongsTo(StoreDocumentRecord::class, 'document_id'); }
    public function segments(): HasMany { return $this->hasMany(StoreDocumentAudioSegment::class, 'track_id')->orderBy('segment_index'); }
}
