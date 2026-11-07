<?php

namespace App\Http\Controllers;

use App\Models\StoreCourse;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseSection;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Services\UploadSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AdminCourseController extends Controller
{
    private function assertCourse(StoreProduct $product): StoreCourse
    {
        abort_unless($product->type === 'course', 404, 'این محصول از نوع دوره آموزشی نیست.');

        return StoreCourse::firstOrCreate(['product_id' => $product->id]);
    }

    public function edit(StoreProduct $product)
    {
        $course = $this->assertCourse($product);
        $course->load(['sections.lessons.resources']);

        return view('admin.store.course', compact('product', 'course'));
    }

    public function update(Request $request, StoreProduct $product)
    {
        $course = $this->assertCourse($product);
        $data = $request->validate([
            'subtitle' => 'nullable|string|max:220',
            'instructor_name' => 'nullable|string|max:160',
            'level' => ['required', Rule::in(['beginner', 'intermediate', 'advanced', 'all'])],
            'language' => 'required|string|size:2',
            'duration_minutes' => 'nullable|integer|min:0|max:100000',
            'access_duration_days' => 'nullable|integer|min:1|max:36500',
            'certificate_enabled' => 'nullable|boolean',
            'certificate_min_completion_percent' => 'required|integer|min:1|max:100',
            'drip_enabled' => 'nullable|boolean',
        ]);

        $data['certificate_enabled'] = $request->boolean('certificate_enabled');
        $data['drip_enabled'] = $request->boolean('drip_enabled');
        $course->update($data);

        return back()->with('status', 'تنظیمات دوره ذخیره شد.');
    }

    public function storeSection(Request $request, StoreProduct $product)
    {
        $course = $this->assertCourse($product);
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:999999',
        ]);
        $course->sections()->create($data + ['sort_order' => $data['sort_order'] ?? 0]);

        return back()->with('status', 'فصل جدید اضافه شد.');
    }

    public function updateSection(Request $request, StoreProduct $product, StoreCourseSection $section)
    {
        $course = $this->assertCourse($product);
        abort_unless($section->course_id === $course->id, 404);
        $section->update($request->validate([
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'required|integer|min:0|max:999999',
        ]));

        return back()->with('status', 'فصل به‌روزرسانی شد.');
    }

    public function deleteSection(StoreProduct $product, StoreCourseSection $section)
    {
        $course = $this->assertCourse($product);
        abort_unless($section->course_id === $course->id, 404);
        $lessonIds = $section->lessons()->pluck('id');
        $this->deleteLessonFiles($product, $lessonIds);
        $section->delete();

        return back()->with('status', 'فصل و جلسات آن حذف شدند.');
    }

    public function storeLesson(Request $request, StoreProduct $product, StoreCourseSection $section)
    {
        $course = $this->assertCourse($product);
        abort_unless($section->course_id === $course->id, 404);
        $data = $request->validate($this->lessonRules());
        $data['slug'] = $this->uniqueLessonSlug($data['title']);
        $data['is_preview'] = $request->boolean('is_preview');
        $data['is_published'] = $request->boolean('is_published');
        $section->lessons()->create($data);

        return back()->with('status', 'جلسه جدید ایجاد شد.');
    }

    public function updateLesson(Request $request, StoreProduct $product, StoreCourseLesson $lesson)
    {
        $course = $this->assertCourse($product);
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        $data = $request->validate($this->lessonRules());
        $data['is_preview'] = $request->boolean('is_preview');
        $data['is_published'] = $request->boolean('is_published');
        $lesson->update($data);

        return back()->with('status', 'جلسه به‌روزرسانی شد.');
    }

    public function deleteLesson(StoreProduct $product, StoreCourseLesson $lesson)
    {
        $course = $this->assertCourse($product);
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        $this->deleteLessonFiles($product, collect([$lesson->id]));
        $lesson->delete();

        return back()->with('status', 'جلسه و منابع آن حذف شدند.');
    }

    public function uploadResource(Request $request, StoreProduct $product, StoreCourseLesson $lesson, UploadSecurityService $security)
    {
        $course = $this->assertCourse($product);
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        $request->validate([
            'file' => 'required|file|max:262144|mimes:pdf,zip,jpg,jpeg,png,webp,mp4,webm,mp3,m4a,doc,docx,xls,xlsx,ppt,pptx,txt',
        ]);

        $upload = $request->file('file');
        $path = $upload->store('store/products/'.$product->id.'/lessons/'.$lesson->id, 'private');
        try {
            $allowed = [
                'application/pdf', 'application/zip', 'image/jpeg', 'image/png', 'image/webp',
                'video/mp4', 'video/webm', 'audio/mpeg', 'audio/mp4', 'audio/webm',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
            ];
            $inspection = $security->inspect($path, $upload->getMimeType(), 256 * 1024 * 1024, $allowed);
            abort_unless($inspection['safe'] ?? false, 503, 'اسکن ضدبدافزار آماده نیست؛ فایل تا تأیید امنیتی قابل استفاده نخواهد بود.');

            StoreProductFile::create([
                'product_id' => $product->id,
                'course_lesson_id' => $lesson->id,
                'purpose' => 'lesson_resource',
                'version' => $product->version,
                'disk' => 'private',
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'mime' => $inspection['mime'],
                'size_bytes' => $inspection['size'],
                'sha256' => $inspection['checksum'],
                'is_primary' => false,
                'is_active' => true,
                'scan_status' => 'clean',
                'scan_engine' => $inspection['scan'],
                'scanned_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                throw $exception;
            }
            abort(503, 'بارگذاری به دلیل ناموفق بودن بررسی امنیتی متوقف شد.');
        }

        return back()->with('status', 'منبع آموزشی پس از اسکن امنیتی ذخیره شد.');
    }

    public function deleteResource(StoreProduct $product, StoreCourseLesson $lesson, StoreProductFile $file)
    {
        $course = $this->assertCourse($product);
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        abort_unless($file->product_id === $product->id && $file->course_lesson_id === $lesson->id && $file->purpose === 'lesson_resource', 404);
        Storage::disk($file->disk ?: 'private')->delete($file->path);
        $file->delete();

        return back()->with('status', 'منبع آموزشی حذف شد.');
    }

    private function lessonRules(): array
    {
        return [
            'title' => 'required|string|max:180',
            'lesson_type' => ['required', Rule::in(['video', 'audio', 'article', 'file', 'live'])],
            'summary' => 'nullable|string|max:2000',
            'content' => 'nullable|string|max:50000',
            'video_url' => 'nullable|url|max:2048',
            'duration_seconds' => 'nullable|integer|min:0|max:86400',
            'drip_after_days' => 'nullable|integer|min:0|max:36500',
            'sort_order' => 'nullable|integer|min:0|max:999999',
        ];
    }

    private function uniqueLessonSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'lesson';
        $slug = $base;
        $index = 1;
        while (StoreCourseLesson::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function deleteLessonFiles(StoreProduct $product, $lessonIds): void
    {
        $files = StoreProductFile::query()
            ->where('product_id', $product->id)
            ->where('purpose', 'lesson_resource')
            ->whereIn('course_lesson_id', $lessonIds)
            ->get();
        foreach ($files as $file) {
            Storage::disk($file->disk ?: 'private')->delete($file->path);
            $file->delete();
        }
    }
}
