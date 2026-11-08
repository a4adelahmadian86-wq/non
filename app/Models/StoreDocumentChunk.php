<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDocumentChunk extends Model
{
    protected $fillable = ['document_id','chunk_index','content_type','page_number','heading','content','structured_content','source_anchor','content_hash'];
    protected $casts = ['chunk_index'=>'integer','page_number'=>'integer','structured_content'=>'array','source_anchor'=>'array'];
    public function document(): BelongsTo { return $this->belongsTo(StoreDocumentRecord::class, 'document_id'); }
}
