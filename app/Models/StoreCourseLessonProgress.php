<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCourseLessonProgress extends Model
{
    protected $fillable = ['enrollment_id', 'lesson_id', 'last_position_seconds', 'completed_at'];
    protected $casts = ['last_position_seconds' => 'integer', 'completed_at' => 'datetime'];

    public function enrollment(): BelongsTo { return $this->belongsTo(StoreCourseEnrollment::class, 'enrollment_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(StoreCourseLesson::class, 'lesson_id'); }
}
