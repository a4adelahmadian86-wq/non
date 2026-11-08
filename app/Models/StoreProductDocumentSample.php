<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentSample extends Model
{
    protected $fillable = ['document_id', 'storage_disk', 'storage_path', 'mime', 'selected_pages', 'sample_format', 'state', 'generated_at'];
    protected $casts = ['selected_pages' => 'array', 'generated_at' => 'datetime'];

    public function document(): BelongsTo { return $this->belongsTo(StoreProductDocument::class, 'document_id'); }
}
