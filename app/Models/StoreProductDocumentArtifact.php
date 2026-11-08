<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductDocumentArtifact extends Model
{
    protected $fillable = ['document_id','artifact_type','version','disk','path','mime','size_bytes','sha256','metadata_json'];
    protected $casts = ['version' => 'integer','size_bytes' => 'integer','metadata_json' => 'array'];

    public function document(): BelongsTo { return $this->belongsTo(StoreProductDocument::class, 'document_id'); }
}
