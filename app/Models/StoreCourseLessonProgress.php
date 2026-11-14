<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StoreCourseLessonProgress extends Model
{
    protected static function booted(): void
    {
        static::updating(function (StoreCourseLessonProgress $progress): void {
            if ($progress->isDirty(['enrollment_id', 'lesson_id'])) {
                throw new LogicException('course_progress_identity_is_immutable');
            }
        });

        static::deleting(function (StoreCourseLessonProgress $progress): void {
            throw new LogicException('course_progress_history_cannot_be_deleted');
        });
    }

    protected $fillable = ['enrollment_id', 'lesson_id', 'last_position_seconds', 'completed_at'];
    protected $casts = ['last_position_seconds' => 'integer', 'completed_at' => 'datetime'];

    public function enrollment(): BelongsTo { return $this->belongsTo(StoreCourseEnrollment::class, 'enrollment_id'); }
    public function lesson(): BelongsTo { return $this->belongsTo(StoreCourseLesson::class, 'lesson_id'); }
}
