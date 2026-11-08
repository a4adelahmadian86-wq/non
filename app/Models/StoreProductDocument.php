<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProductDocument extends Model
{
    protected $fillable = [
        'product_id','product_file_id','source_sha256','processing_status','detected_type',
        'page_count','word_count','character_count','language','extraction_version',
        'structure_json','failure_code','processed_at',
    ];

    protected $casts = [
        'page_count' => 'integer',
        'word_count' => 'integer',
        'character_count' => 'integer',
        'extraction_version' => 'integer',
        'structure_json' => 'array',
        'processed_at' => 'datetime',
    ];

    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'product_file_id'); }
    public function chunks(): HasMany { return $this->hasMany(StoreProductDocumentChunk::class, 'document_id')->orderBy('chunk_index'); }
    public function artifacts(): HasMany { return $this->hasMany(StoreProductDocumentArtifact::class, 'document_id'); }
    public function facts(): HasMany { return $this->hasMany(StoreProductDocumentFact::class, 'document_id'); }
}
