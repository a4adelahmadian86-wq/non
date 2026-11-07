<?php

namespace App\Http\Controllers;

use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseLessonProgress;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreCourseLearningController extends Controller
{
    private function enrollment(Request $request, StoreProduct $product): StoreCourseEnrollment
    {
        abort_unless($product->type === 'course' && $product->course, 404);
        $libraryItem = StoreLibraryItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->whereNull('revoked_at')
            ->latest('granted_at')
            ->firstOrFail();

        $course = $product->course;
        $enrollment = StoreCourseEnrollment::firstOrCreate(
            ['library_item_id' => $libraryItem->id],
            [
                'course_id' => $course->id,
                'product_id' => $product->id,
                'user_id' => $request->user()->id,
                'starts_at' => $libraryItem->granted_at ?? now(),
                'expires_at' => $course->access_duration_days
                    ? ($libraryItem->granted_at ?? now())->copy()->addDays($course->access_duration_days)
                    : null,
            ]
        );

        abort_unless($enrollment->user_id === $request->user()->id, 404);
        abort_if($enrollment->expires_at && $enrollment->expires_at->isPast(), 403, 'مدت دسترسی این دوره به پایان رسیده است.');

        return $enrollment;
    }

    private function lessonAvailable(StoreCourseEnrollment $enrollment, StoreCourseLesson $lesson): bool
    {
        if (! $enrollment->course?->drip_enabled || $lesson->drip_after_days === null) {
            return true;
        }

        return now()->greaterThanOrEqualTo($enrollment->starts_at->copy()->addDays((int) $lesson->drip_after_days));
    }

    public function show(Request $request, StoreProduct $product)
    {
        $enrollment = $this->enrollment($request, $product);
        $enrollment->load(['course.sections.lessons.resources', 'progress']);
        $sections = $enrollment->course->sections->map(function ($section) {
            $section->setRelation('lessons', $section->lessons->filter(fn ($lesson) => $lesson->is_published && $this->lessonAvailable($enrollment, $lesson))->values());
            return $section;
        })->filter(fn ($section) => $section->lessons->isNotEmpty())->values();

        $total = $sections->sum(fn ($section) => $section->lessons->count());
        $completed = $enrollment->progress->whereNotNull('completed_at')->count();
        $progressPercent = $total > 0 ? (int) floor($completed * 100 / $total) : 0;

        return view('store.course-learning', compact('product', 'enrollment', 'sections', 'total', 'completed', 'progressPercent'));
    }

    public function lesson(Request $request, StoreProduct $product, StoreCourseLesson $lesson)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($this->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');
        $lesson->load(['resources' => fn ($query) => $query->where('is_active', true)->where('scan_status', 'clean')]);
        $progress = StoreCourseLessonProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
        ]);

        return view('store.course-lesson', compact('product', 'enrollment', 'lesson', 'progress'));
    }

    public function progress(Request $request, StoreProduct $product, StoreCourseLesson $lesson)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($this->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');
        $data = $request->validate([
            'last_position_seconds' => 'nullable|integer|min:0|max:86400',
            'completed' => 'nullable|boolean',
        ]);

        $progress = StoreCourseLessonProgress::updateOrCreate(
            ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
            [
                'last_position_seconds' => $data['last_position_seconds'] ?? 0,
                'completed_at' => $request->boolean('completed') ? now() : null,
            ]
        );
        $enrollment->update(['last_lesson_id' => $lesson->id]);

        if ($request->boolean('completed')) {
            $total = $enrollment->course->sections()->whereHas('lessons', fn ($query) => $query->where('is_published', true))->withCount(['lessons as published_lessons_count' => fn ($query) => $query->where('is_published', true)])->get()->sum('published_lessons_count');
            $done = $enrollment->progress()->whereNotNull('completed_at')->count();
            if ($total > 0 && $done >= $total) {
                $enrollment->update(['completed_at' => now()]);
            }
        }

        return response()->json(['ok' => true, 'completed' => (bool) $progress->completed_at]);
    }

    public function resource(Request $request, StoreProduct $product, StoreCourseLesson $lesson, StoreProductFile $file)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($this->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');
        abort_unless(
            $file->product_id === $product->id
                && $file->course_lesson_id === $lesson->id
                && $file->purpose === 'lesson_resource'
                && $file->is_active
                && $file->scan_status === 'clean',
            404
        );

        $disk = Storage::disk($file->disk ?: 'private');
        abort_unless($disk->exists($file->path), 404);
        if ($file->sha256) {
            $actualHash = hash_file('sha256', $disk->path($file->path));
            abort_unless(is_string($actualHash) && hash_equals((string) $file->sha256, $actualHash), 409, 'محتوای منبع آموزشی پس از اسکن امنیتی تغییر کرده است.');
        }

        $inline = str_starts_with((string) $file->mime, 'video/') || str_starts_with((string) $file->mime, 'audio/');
        return $disk->response($file->path, $file->original_name ?: basename($file->path), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $inline ? 'inline' : 'attachment',
        ]);
    }
}
