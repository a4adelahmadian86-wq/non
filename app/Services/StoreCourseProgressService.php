<?php

namespace App\Services;

use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use Illuminate\Support\Collection;

class StoreCourseProgressService
{
    public function lessonAvailable(StoreCourseEnrollment $enrollment, StoreCourseLesson $lesson): bool
    {
        if (! $enrollment->course?->drip_enabled || $lesson->drip_after_days === null) {
            return true;
        }

        return now()->greaterThanOrEqualTo(
            $enrollment->starts_at->copy()->addDays((int) $lesson->drip_after_days)
        );
    }

    /**
     * Return the published lessons currently released to this enrollment.
     *
     * @return Collection<int, StoreCourseLesson>
     */
    public function availableLessons(StoreCourseEnrollment $enrollment): Collection
    {
        $enrollment->loadMissing(['course.sections.lessons']);

        if (! $enrollment->course) {
            return collect();
        }

        return $enrollment->course->sections
            ->flatMap(fn ($section) => $section->lessons
                ->filter(fn (StoreCourseLesson $lesson) => $lesson->is_published && $this->lessonAvailable($enrollment, $lesson))
                ->values())
            ->values();
    }

    /**
     * Shared progress calculation for the learner experience and CRM.
     *
     * @return array{total:int,completed:int,progress_percent:int,available_lesson_ids:array<int,int>}
     */
    public function summarize(StoreCourseEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['progress']);
        $availableLessonIds = $this->availableLessons($enrollment)->pluck('id')->map(fn ($id) => (int) $id)->values();
        $total = $availableLessonIds->count();
        $completed = $enrollment->progress
            ->whereIn('lesson_id', $availableLessonIds)
            ->whereNotNull('completed_at')
            ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'progress_percent' => $total > 0 ? min(100, (int) floor($completed * 100 / $total)) : 0,
            'available_lesson_ids' => $availableLessonIds->all(),
        ];
    }

    public function syncCompletion(StoreCourseEnrollment $enrollment): StoreCourseEnrollment
    {
        $summary = $this->summarize($enrollment);
        $completedAt = $summary['total'] > 0 && $summary['completed'] >= $summary['total']
            ? ($enrollment->completed_at ?? now())
            : null;

        if ($enrollment->completed_at != $completedAt) {
            $enrollment->update(['completed_at' => $completedAt]);
        }

        return $enrollment->fresh();
    }
}
