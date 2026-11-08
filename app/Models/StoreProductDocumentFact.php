<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentFact extends Model
{
    protected $fillable = [
        'document_id',
        'source_sha256',
        'fact_type',
        'fact_key',
        'fact_value',
        'evidence_text',
        'locator_json',
        'verification_status',
    ];

    protected $casts = [
        'locator_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StoreProductDocument::class, 'document_id');
    }
}
