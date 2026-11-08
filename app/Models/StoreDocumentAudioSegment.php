<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class StoreDocumentAudioSegment extends Model
{
    protected $fillable = ['track_id','segment_index','start_ms','end_ms','text','source_anchor'];
    protected $casts = ['segment_index'=>'integer','start_ms'=>'integer','end_ms'=>'integer','source_anchor'=>'array'];
    public function track(): BelongsTo { return $this->belongsTo(StoreDocumentAudioTrack::class, 'track_id'); }
}
