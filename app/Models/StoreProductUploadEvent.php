<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductUploadEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['upload_session_id', 'stage', 'state', 'title', 'detail', 'metadata', 'created_at'];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StoreProductUploadSession::class, 'upload_session_id');
    }
}
