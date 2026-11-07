<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCourseLesson extends Model
{
    protected $fillable = [
        'section_id', 'title', 'slug', 'lesson_type', 'summary', 'content', 'video_url',
        'duration_seconds', 'drip_after_days', 'is_preview', 'is_published', 'sort_order',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'drip_after_days' => 'integer',
        'is_preview' => 'boolean',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function section(): BelongsTo { return $this->belongsTo(StoreCourseSection::class, 'section_id'); }
    public function resources(): HasMany
    {
        return $this->hasMany(StoreProductFile::class, 'course_lesson_id')
            ->where('purpose', 'lesson_resource')->orderBy('id');
    }
}
