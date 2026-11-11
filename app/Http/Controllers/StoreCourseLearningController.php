<?php

namespace App\Http\Controllers;

use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseLessonProgress;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Services\StoreCourseEnrollmentService;
use App\Services\StoreCourseProgressService;
use App\Services\Storage\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreCourseLearningController extends Controller
{
    private function enrollment(Request $request, StoreProduct $product): StoreCourseEnrollment
    {
        abort_unless($product->type === 'course' && $product->course, 404);
        $libraryItems = StoreLibraryItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->whereNull('revoked_at')
            ->orderByDesc('granted_at')
            ->get();

        abort_if($libraryItems->isEmpty(), 404);
        foreach ($libraryItems as $libraryItem) {
            $enrollment = app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);
            if ($enrollment && (! $enrollment->expires_at || $enrollment->expires_at->isFuture())) {
                abort_unless($enrollment->user_id === $request->user()->id, 404);
                return $enrollment;
            }
        }

        abort(403, 'مدت دسترسی این دوره به پایان رسیده است.');
    }

    public function show(Request $request, StoreProduct $product, StoreCourseProgressService $courseProgress)
    {
        $enrollment = $this->enrollment($request, $product);
        $enrollment->load(['course.sections.lessons.resources', 'progress']);
        $sections = $enrollment->course->sections->map(function ($section) use ($enrollment, $courseProgress) {
            $section->setRelation('lessons', $section->lessons->filter(fn ($lesson) => $lesson->is_published && $courseProgress->lessonAvailable($enrollment, $lesson))->values());
            return $section;
        })->filter(fn ($section) => $section->lessons->isNotEmpty())->values();

        $summary = $courseProgress->summarize($enrollment);
        $total = $summary['total'];
        $completed = $summary['completed'];
        $progressPercent = $summary['progress_percent'];

        return view('store.course-learning', compact('product', 'enrollment', 'sections', 'total', 'completed', 'progressPercent'));
    }

    public function lesson(Request $request, StoreProduct $product, StoreCourseLesson $lesson, StoreCourseProgressService $courseProgress)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($courseProgress->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');
        $lesson->load(['resources' => fn ($query) => $query->where('is_active', true)->where('scan_status', 'clean')]);
        $progress = StoreCourseLessonProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
        ]);

        return view('store.course-lesson', compact('product', 'enrollment', 'lesson', 'progress'));
    }

    public function progress(Request $request, StoreProduct $product, StoreCourseLesson $lesson, StoreCourseProgressService $courseProgress)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($courseProgress->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');

        $data = $request->validate([
            'last_position_seconds' => 'sometimes|integer|min:0|max:86400',
            'completed' => 'sometimes|boolean',
        ]);

        $current = StoreCourseLessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $attributes = [];
        if (array_key_exists('last_position_seconds', $data)) {
            $attributes['last_position_seconds'] = $data['last_position_seconds'];
        }
        if (array_key_exists('completed', $data)) {
            $attributes['completed_at'] = $request->boolean('completed') ? now() : null;
        }

        $progress = $attributes
            ? StoreCourseLessonProgress::updateOrCreate(
                ['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id],
                $attributes
            )
            : ($current ?? StoreCourseLessonProgress::firstOrCreate([
                'enrollment_id' => $enrollment->id,
                'lesson_id' => $lesson->id,
            ]));

        $enrollment->update(['last_lesson_id' => $lesson->id]);

        if (array_key_exists('completed', $data)) {
            $courseProgress->syncCompletion($enrollment->fresh(['course.sections.lessons', 'progress']));
        }

        return response()->json(['ok' => true, 'completed' => (bool) $progress->completed_at]);
    }

    public function resource(Request $request, StoreProduct $product, StoreCourseLesson $lesson, StoreProductFile $file, StorageManager $storage, StoreCourseProgressService $courseProgress)
    {
        $enrollment = $this->enrollment($request, $product);
        abort_unless($lesson->section()->where('course_id', $enrollment->course_id)->exists() && $lesson->is_published, 404);
        abort_unless($courseProgress->lessonAvailable($enrollment, $lesson), 403, 'این جلسه طبق برنامه انتشار تدریجی هنوز در دسترس نیست.');
        abort_unless(
            $file->product_id === $product->id
                && $file->course_lesson_id === $lesson->id
                && $file->purpose === 'lesson_resource'
                && $file->is_active
                && $file->scan_status === 'clean',
            404
        );

        $disk = $file->disk ?: 'private';
        abort_unless($storage->exists($disk, $file->path), 404);
        $temporary = tempnam(sys_get_temp_dir(), 'farast-course-resource');
        try {
            $storage->downloadTo($disk, $file->path, $temporary);
            if ($file->sha256) {
                $actualHash = hash_file('sha256', $temporary);
                abort_unless(is_string($actualHash) && hash_equals((string) $file->sha256, $actualHash), 409, 'محتوای منبع آموزشی پس از اسکن امنیتی تغییر کرده است.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }

        $inline = str_starts_with((string) $file->mime, 'video/') || str_starts_with((string) $file->mime, 'audio/');
        return response()->stream(function () use ($temporary) {
            $stream = fopen($temporary, 'rb');
            try {
                if (is_resource($stream)) fpassthru($stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
                @unlink($temporary);
            }
        }, 200, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'Content-Length' => (string) filesize($temporary),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $inline ? 'inline' : 'attachment',
        ]);
    }
}
