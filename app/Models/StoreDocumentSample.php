<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDocumentSample extends Model
{
    protected $fillable = ['document_id','source_sha256','disk','path','mime','kind','selected_pages','status'];
    protected $casts = ['selected_pages'=>'array'];
    public function document(): BelongsTo { return $this->belongsTo(StoreDocumentRecord::class, 'document_id'); }
}
