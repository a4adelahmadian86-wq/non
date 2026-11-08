<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentBlock extends Model
{
    protected $fillable = ['document_id', 'sequence', 'block_type', 'page_number', 'section_path', 'text_content', 'metadata', 'content_sha256'];
    protected $casts = ['sequence' => 'integer', 'page_number' => 'integer', 'metadata' => 'array'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StoreProductDocument::class, 'document_id');
    }
}
