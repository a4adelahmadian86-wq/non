<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentChunk extends Model
{
    protected $fillable = ['document_id','chunk_index','chunk_type','locator_json','content_text','content_sha256','metadata_json'];
    protected $casts = ['chunk_index' => 'integer','locator_json' => 'array','metadata_json' => 'array'];

    public function document(): BelongsTo { return $this->belongsTo(StoreProductDocument::class, 'document_id'); }
}
