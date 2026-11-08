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
use Illuminate\Validation\ValidationException;
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
        $this->audit('store.course.settings_updated', $product, ['course_id' => $course->id]);

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
        $section = $course->sections()->create($data + ['sort_order' => $data['sort_order'] ?? 0]);
        $this->audit('store.course.section_created', $product, ['section_id' => $section->id]);

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
        $this->audit('store.course.section_updated', $product, ['section_id' => $section->id]);

        return back()->with('status', 'فصل به‌روزرسانی شد.');
    }

    public function deleteSection(StoreProduct $product, StoreCourseSection $section)
    {
        $paths = DB::transaction(function () use ($product, $section) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $course = $this->assertCourse($lockedProduct);
            $lockedSection = $course->sections()->whereKey($section->id)->lockForUpdate()->firstOrFail();
            $lessonIds = $lockedSection->lessons()->pluck('id');
            $this->assertPublishedCourseKeepsAValidLesson($lockedProduct, $lessonIds);
            $paths = $this->deleteLessonFiles($lockedProduct, $lessonIds);
            $this->audit('store.course.section_deleted', $lockedProduct, ['section_id' => $lockedSection->id, 'lesson_ids' => $lessonIds->all()]);
            $lockedSection->delete();

            return $paths;
        });
        $this->deleteStoredFiles($paths);

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
        $this->assertLessonCanBePublished($data);
        $lesson = DB::transaction(function () use ($product, $section, $data) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedCourse = $this->assertCourse($lockedProduct);
            $lockedSection = $lockedCourse->sections()->whereKey($section->id)->lockForUpdate()->firstOrFail();
            $lesson = $lockedSection->lessons()->create($data);
            $this->audit('store.course.lesson_created', $lockedProduct, ['section_id' => $lockedSection->id, 'lesson_id' => $lesson->id]);

            return $lesson;
        });

        return back()->with('status', 'جلسه جدید ایجاد شد.');
    }

    public function updateLesson(Request $request, StoreProduct $product, StoreCourseLesson $lesson)
    {
        $course = $this->assertCourse($product);
        abort_unless($lesson->section()->where('course_id', $course->id)->exists(), 404);
        $data = $request->validate($this->lessonRules());
        $data['is_preview'] = $request->boolean('is_preview');
        $data['is_published'] = $request->boolean('is_published');
        $this->assertLessonCanBePublished($data, $lesson);
        DB::transaction(function () use ($product, $lesson, $data) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedCourse = $this->assertCourse($lockedProduct);
            $lockedLesson = StoreCourseLesson::query()
                ->whereKey($lesson->id)
                ->whereHas('section', fn ($query) => $query->where('course_id', $lockedCourse->id))
                ->lockForUpdate()
                ->firstOrFail();
            $lockedLesson->update($data);
            $this->audit('store.course.lesson_updated', $lockedProduct, ['lesson_id' => $lockedLesson->id, 'is_published' => $lockedLesson->is_published]);
        });

        return back()->with('status', 'جلسه به‌روزرسانی شد.');
    }

    public function deleteLesson(StoreProduct $product, StoreCourseLesson $lesson)
    {
        $paths = DB::transaction(function () use ($product, $lesson) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $course = $this->assertCourse($lockedProduct);
            $lockedLesson = StoreCourseLesson::query()
                ->whereKey($lesson->id)
                ->whereHas('section', fn ($query) => $query->where('course_id', $course->id))
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertPublishedCourseKeepsAValidLesson($lockedProduct, collect([$lockedLesson->id]));
            $paths = $this->deleteLessonFiles($lockedProduct, collect([$lockedLesson->id]));
            $this->audit('store.course.lesson_deleted', $lockedProduct, ['lesson_id' => $lockedLesson->id]);
            $lockedLesson->delete();

            return $paths;
        });
        $this->deleteStoredFiles($paths);

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

            DB::transaction(function () use ($product, $lesson, $upload, $inspection, $path) {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $course = $this->assertCourse($lockedProduct);
                $lockedLesson = StoreCourseLesson::query()
                    ->whereKey($lesson->id)
                    ->whereHas('section', fn ($query) => $query->where('course_id', $course->id))
                    ->lockForUpdate()
                    ->firstOrFail();
                $resource = $lockedLesson->resources()->create([
                    'product_id' => $lockedProduct->id,
                    'course_lesson_id' => $lockedLesson->id,
                    'purpose' => 'lesson_resource',
                    'version' => $lockedProduct->version,
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
                $this->audit('store.course.resource_uploaded', $lockedProduct, ['lesson_id' => $lockedLesson->id, 'file_id' => $resource->id, 'mime' => $resource->mime, 'size_bytes' => $resource->size_bytes, 'sha256' => $resource->sha256]);
            });
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
        [$disk, $path] = DB::transaction(function () use ($product, $lesson, $file) {
            $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $course = $this->assertCourse($lockedProduct);
            $lockedLesson = StoreCourseLesson::query()
                ->whereKey($lesson->id)
                ->whereHas('section', fn ($query) => $query->where('course_id', $course->id))
                ->lockForUpdate()
                ->firstOrFail();
            $lockedFile = $lockedLesson->resources()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            if ($lockedLesson->is_published && ! $this->lessonHasPublishableContent($lockedLesson->toArray(), $lockedLesson, $lockedFile->id)) {
                throw ValidationException::withMessages(['file' => 'این منبع برای محتوای منتشرشده ضروری است؛ ابتدا منبع جایگزین ثبت یا جلسه را از انتشار خارج کنید.']);
            }
            $this->audit('store.course.resource_deleted', $lockedProduct, ['lesson_id' => $lockedLesson->id, 'file_id' => $lockedFile->id]);
            $disk = $lockedFile->disk ?: 'private';
            $path = $lockedFile->path;
            $lockedFile->delete();

            return [$disk, $path];
        });
        Storage::disk($disk)->delete($path);

        return back()->with('status', 'منبع آموزشی حذف شد.');
    }

    private function audit(string $eventType, StoreProduct $product, array $meta = []): void
    {
        app(\App\Services\AuditEventService::class)->record(
            $eventType,
            auth()->id(),
            null,
            'store_product',
            $product->id,
            $meta,
            'admin_console'
        );
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

    private function assertLessonCanBePublished(array $data, ?StoreCourseLesson $lesson = null): void
    {
        if (! ($data['is_published'] ?? false) || $this->lessonHasPublishableContent($data, $lesson)) {
            return;
        }

        throw ValidationException::withMessages([
            'is_published' => match ($data['lesson_type'] ?? '') {
                'article' => 'برای انتشار جلسه متنی، محتوای آموزشی را وارد کنید.',
                'video' => 'برای انتشار جلسه ویدئویی، لینک ویدئو یا منبع ویدئویی سالم لازم است.',
                'audio' => 'برای انتشار جلسه صوتی، لینک صوت یا منبع صوتی سالم لازم است.',
                'file' => 'برای انتشار جلسه فایلی، دست‌کم یک منبع سالم بارگذاری کنید.',
                'live' => 'برای انتشار جلسه زنده، لینک جلسه یا توضیحات برگزاری را وارد کنید.',
                default => 'محتوای جلسه برای انتشار کامل نیست.',
            },
        ]);
    }

    private function lessonHasPublishableContent(array $data, ?StoreCourseLesson $lesson = null, ?int $excludeResourceId = null): bool
    {
        $type = $data['lesson_type'] ?? $lesson?->lesson_type;
        $hasUrl = filled(array_key_exists('video_url', $data) ? $data['video_url'] : $lesson?->video_url);
        $hasContent = filled(array_key_exists('content', $data) ? $data['content'] : $lesson?->content);
        $resources = $lesson?->resources()
            ->where('is_active', true)
            ->where('scan_status', 'clean')
            ->when($excludeResourceId, fn ($query) => $query->where('id', '!=', $excludeResourceId));

        return match ($type) {
            'article' => $hasContent,
            'video' => $hasUrl || ($resources?->where('mime', 'like', 'video/%')->exists() ?? false),
            'audio' => $hasUrl || ($resources?->where('mime', 'like', 'audio/%')->exists() ?? false),
            'file' => $resources?->exists() ?? false,
            'live' => $hasUrl || $hasContent,
            default => false,
        };
    }

    private function assertPublishedCourseKeepsAValidLesson(StoreProduct $product, $removedLessonIds): void
    {
        if ($product->status !== 'published') {
            return;
        }

        $hasReplacement = $product->course()
            ->whereHas('sections.lessons', fn ($query) => $query->publishable()->whereNotIn('id', $removedLessonIds))
            ->exists();

        if (! $hasReplacement) {
            throw ValidationException::withMessages([
                'lesson' => 'این تغییر آخرین جلسه معتبر دوره منتشرشده را حذف می‌کند؛ ابتدا دوره را از انتشار خارج کنید یا جلسه جایگزین بسازید.',
            ]);
        }
    }

    private function deleteLessonFiles(StoreProduct $product, $lessonIds): array
    {
        $files = StoreProductFile::query()
            ->where('product_id', $product->id)
            ->where('purpose', 'lesson_resource')
            ->whereIn('course_lesson_id', $lessonIds)
            ->lockForUpdate()
            ->get();
        $paths = [];
        foreach ($files as $file) {
            $paths[] = [$file->disk ?: 'private', $file->path];
            $file->delete();
        }

        return $paths;
    }

    private function deleteStoredFiles(array $paths): void
    {
        foreach ($paths as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }
}
