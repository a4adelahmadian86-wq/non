<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarastDocument extends Model
{
    protected $table = 'farast_documents';

    protected $fillable = [
        'user_id',
        'project_id',
        'folder_id',
        'title',
        'content',
        'content_json',
        'document_format',
        'page_settings',
        'revision',
        'last_saved_at',
        'status',
        'is_favorite',
        'trashed_at',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'page_settings' => 'array',
            'revision' => 'integer',
            'last_saved_at' => 'datetime',
            'is_favorite' => 'boolean',
            'trashed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FarastDocumentVersion::class, 'document_id');
    }
}
