<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarastDocumentVersion extends Model
{
    protected $table = 'farast_document_versions';

    protected $fillable = [
        'document_id',
        'user_id',
        'content',
        'content_json',
        'label',
    ];

    protected function casts(): array
    {
        return ['content_json' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FarastDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
