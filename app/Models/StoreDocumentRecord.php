<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StoreDocumentRecord extends Model
{
    protected $fillable = ['product_id','file_id','source_sha256','detected_mime','detected_type','language','page_count','word_count','extracted_characters','status','content_version','knowledge_version','metadata','last_error_code','processed_at'];
    protected $casts = ['page_count'=>'integer','word_count'=>'integer','extracted_characters'=>'integer','content_version'=>'integer','knowledge_version'=>'integer','metadata'=>'array','processed_at'=>'datetime'];
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'file_id'); }
    public function chunks(): HasMany { return $this->hasMany(StoreDocumentChunk::class, 'document_id')->orderBy('chunk_index'); }
    public function facts(): HasMany { return $this->hasMany(StoreDocumentFact::class, 'document_id'); }
    public function sample(): HasOne { return $this->hasOne(StoreDocumentSample::class, 'document_id'); }
    public function audioTrack(): HasOne { return $this->hasOne(StoreDocumentAudioTrack::class, 'document_id'); }
}
