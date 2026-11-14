<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StoreCourseEnrollment extends Model
{
    protected static function booted(): void
    {
        static::updating(function (StoreCourseEnrollment $enrollment): void {
            if ($enrollment->isDirty(['course_id', 'product_id', 'user_id', 'library_item_id', 'starts_at', 'expires_at'])) {
                throw new LogicException('course_enrollment_identity_is_immutable');
            }
        });

        static::deleting(function (StoreCourseEnrollment $enrollment): void {
            throw new LogicException('course_enrollment_history_cannot_be_deleted');
        });
    }

    protected $fillable = [
        'course_id', 'product_id', 'user_id', 'library_item_id', 'last_lesson_id',
        'starts_at', 'expires_at', 'completed_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function course(): BelongsTo { return $this->belongsTo(StoreCourse::class, 'course_id'); }
    public function product(): BelongsTo { return $this->belongsTo(StoreProduct::class, 'product_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function libraryItem(): BelongsTo { return $this->belongsTo(StoreLibraryItem::class, 'library_item_id'); }
    public function progress(): HasMany { return $this->hasMany(StoreCourseLessonProgress::class, 'enrollment_id'); }
}
