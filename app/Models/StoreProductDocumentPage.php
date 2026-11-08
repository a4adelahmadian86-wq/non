<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentPage extends Model
{
    protected $fillable = [
        'document_id',
        'page_number',
        'page_kind',
        'locator_key',
        'locator_json',
        'content_text',
        'content_html',
        'content_sha256',
        'has_extracted_text',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'locator_json' => 'array',
        'has_extracted_text' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StoreProductDocument::class, 'document_id');
    }
}
