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

    public function scopePublishable($query)
    {
        return $query->where('is_published', true)->where(function ($lesson) {
            $lesson->where(function ($article) {
                $article->where('lesson_type', 'article')
                    ->whereNotNull('content')
                    ->whereRaw('TRIM(content) <> ?', ['']);
            })->orWhere(function ($video) {
                $video->where('lesson_type', 'video')->where(function ($source) {
                    $source->where(fn ($url) => $url->whereNotNull('video_url')->whereRaw('TRIM(video_url) <> ?', ['']))
                        ->orWhereHas('resources', fn ($file) => $file->where('is_active', true)->where('scan_status', 'clean')->where('mime', 'like', 'video/%'));
                });
            })->orWhere(function ($audio) {
                $audio->where('lesson_type', 'audio')->where(function ($source) {
                    $source->where(fn ($url) => $url->whereNotNull('video_url')->where('video_url', '!=', ''))
                        ->orWhereHas('resources', fn ($file) => $file->where('is_active', true)->where('scan_status', 'clean')->where('mime', 'like', 'audio/%'));
                });
            })->orWhere(function ($fileLesson) {
                $fileLesson->where('lesson_type', 'file')
                    ->whereHas('resources', fn ($file) => $file->where('is_active', true)->where('scan_status', 'clean'));
            })->orWhere(function ($live) {
                $live->where('lesson_type', 'live')->where(function ($source) {
                    $source->where(fn ($url) => $url->whereNotNull('video_url')->where('video_url', '!=', ''))
                        ->orWhere(fn ($content) => $content->whereNotNull('content')->whereRaw('TRIM(content) <> ?', ['']));
                });
            });
        });
    }
}
