<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDocumentFact extends Model
{
    protected $fillable = ['document_id','fact_key','fact_value','confidence','evidence','model','knowledge_version'];
    protected $casts = ['confidence'=>'decimal:4','evidence'=>'array','knowledge_version'=>'integer'];
    public function document(): BelongsTo { return $this->belongsTo(StoreDocumentRecord::class, 'document_id'); }
}
