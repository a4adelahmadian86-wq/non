<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCourseSection extends Model
{
    protected $fillable = ['course_id', 'title', 'description', 'sort_order'];
    protected $casts = ['sort_order' => 'integer'];

    public function course(): BelongsTo { return $this->belongsTo(StoreCourse::class, 'course_id'); }
    public function lessons(): HasMany { return $this->hasMany(StoreCourseLesson::class, 'section_id')->orderBy('sort_order')->orderBy('id'); }
}
