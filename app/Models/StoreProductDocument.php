<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StoreProductDocument extends Model
{
    protected $fillable = [
        'product_id', 'product_file_id', 'upload_session_id', 'source_sha256', 'original_name', 'detected_mime',
        'extension', 'size_bytes', 'storage_disk', 'storage_path', 'processing_state',
        'page_count', 'duration_seconds', 'content_version', 'technical_metadata',
        'content_manifest', 'content_studied_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'page_count' => 'integer',
        'duration_seconds' => 'integer',
        'content_version' => 'integer',
        'technical_metadata' => 'array',
        'content_manifest' => 'array',
        'content_studied_at' => 'datetime',
    ];

    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'product_file_id'); }
    public function uploadSession(): BelongsTo { return $this->belongsTo(StoreProductUploadSession::class, 'upload_session_id'); }
    public function blocks(): HasMany { return $this->hasMany(StoreProductDocumentBlock::class, 'document_id')->orderBy('sequence'); }
    public function sample(): HasOne { return $this->hasOne(StoreProductDocumentSample::class, 'document_id'); }
    public function audio(): HasOne { return $this->hasOne(StoreProductDocumentAudio::class, 'document_id'); }
}
