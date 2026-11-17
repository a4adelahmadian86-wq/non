<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreCourseLesson;
use App\Models\StoreProduct;
use App\Models\StoreProductFile;
use App\Models\StoreProductIngestion;
use App\Services\AuditEventService;
use App\Services\UploadSecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminCourseResourceIngestionController extends Controller
{
    private const MAX_UPLOAD_BYTES = 256 * 1024 * 1024;
    private const MAX_PROCESSING_BYTES = 25 * 1024 * 1024;
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'zip', 'tar', 'tar.gz', 'tar.bz2', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'md',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'mp3', 'wav', 'ogg', 'flac', 'm4a', 'mp4', 'webm', 'mov',
    ];

    public function preflight(Request $request, StoreProduct $product, StoreCourseLesson $lesson, UploadSecurityService $security)
    {
        $this->assertLessonBelongsToProduct($product, $lesson);
        $request->validate(['file' => 'required|file|max:262144']);

        $upload = $request->file('file');
        $extension = $this->extension($upload->getClientOriginalName());
        abort_unless(in_array($extension, self::ALLOWED_EXTENSIONS, true), 422, 'این فرمت در فهرست فرمت‌های مجاز فراست نیست.');

        $this->pruneExpiredStaging($product->id);
        $safeName = mb_substr(basename(str_replace(chr(92), '/', $upload->getClientOriginalName())), 0, 255);
        $path = $upload->storeAs(
            'store/quarantine/course-ingestions/'.$product->id.'/'.$lesson->id,
            (string) Str::uuid().'.'.$extension,
            'private'
        );

        if (! $path) {
            return response()->json(['message' => 'ذخیره موقت منبع در قرنطینه خصوصی ممکن نشد.'], 503);
        }

        try {
            $inspection = $security->inspect($path, $upload->getMimeType(), self::MAX_UPLOAD_BYTES);
            abort_unless($inspection['safe'] ?? false, 422, 'منبع از بررسی ایستای داخلی عبور نکرده است.');

            $ingestion = StoreProductIngestion::create([
                'id' => (string) Str::uuid(),
                'product_id' => $product->id,
                'course_lesson_id' => $lesson->id,
                'user_id' => $request->user()->id,
                'status' => 'inspected',
                'staging_disk' => 'private',
                'staging_path' => $path,
                'original_name' => $safeName,
                'mime' => $inspection['mime'],
                'size_bytes' => $inspection['size'],
                'sha256' => $inspection['checksum'],
                'inspection_json' => $inspection,
                'is_primary' => false,
                'expires_at' => now()->addMinutes(30),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($path);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            return response()->json([
                'message' => 'بررسی اولیه منبع کامل نشد؛ منبع وارد دوره نشده است.',
                'code' => class_basename($exception) === 'RuntimeException' ? $exception->getMessage() : 'inspection_failed',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'ingestion_id' => $ingestion->id,
            'expires_at' => $ingestion->expires_at?->toISOString(),
            'file' => [
                'name' => $ingestion->original_name,
                'mime' => $ingestion->mime,
                'size_bytes' => $ingestion->size_bytes,
                'sha256' => $ingestion->sha256,
            ],
            'inspection' => $inspection,
        ], 201);
    }

    public function confirm(
        Request $request,
        StoreProduct $product,
        StoreCourseLesson $lesson,
        string $ingestionId,
        UploadSecurityService $security,
        AuditEventService $audit
    ) {
        $this->assertLessonBelongsToProduct($product, $lesson);
        $ingestion = StoreProductIngestion::query()
            ->whereKey($ingestionId)
            ->where('product_id', $product->id)
            ->where('course_lesson_id', $lesson->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'inspected')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        abort_unless(($ingestion->staging_disk ?: 'private') === 'private', 409, 'فضای قرنطینه منبع معتبر نیست.');
        $disk = Storage::disk('private');
        abort_unless($disk->exists($ingestion->staging_path), 404, 'فایل قرنطینه‌شده پیدا نشد.');

        $inspection = $security->inspect($ingestion->staging_path, $ingestion->mime, self::MAX_UPLOAD_BYTES);
        if (! ($inspection['safe'] ?? false)
            || (int) $inspection['size'] !== (int) $ingestion->size_bytes
            || ! hash_equals((string) $ingestion->sha256, (string) $inspection['checksum'])) {
            return response()->json([
                'message' => 'محتوای فایل قرنطینه‌شده با گزارش اولیه یکسان نیست؛ ذخیره نهایی متوقف شد.',
                'code' => 'staged_source_changed',
            ], 409);
        }

        $extension = $this->extension($ingestion->original_name);
        $finalPath = 'store/products/'.$product->id.'/lessons/'.$lesson->id.'/'.Str::uuid().'.'.$extension;
        if (! $disk->move($ingestion->staging_path, $finalPath)) {
            return response()->json(['message' => 'انتقال امن منبع به فضای خصوصی نهایی ممکن نشد.'], 503);
        }

        $documentId = null;
        $processingStatus = (int) $ingestion->size_bytes <= self::MAX_PROCESSING_BYTES ? 'pending' : 'awaiting_provider';

        try {
            DB::transaction(function () use (
                $product, $lesson, $ingestion, $inspection, $finalPath, $processingStatus,
                $request, $audit, &$documentId
            ): void {
                $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $lockedLesson = StoreCourseLesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
                $lockedIngestion = StoreProductIngestion::query()->whereKey($ingestion->id)->lockForUpdate()->firstOrFail();

                $this->assertLessonBelongsToProduct($lockedProduct, $lockedLesson);
                abort_unless(
                    $lockedIngestion->status === 'inspected'
                    && $lockedIngestion->expires_at?->isFuture()
                    && (int) $lockedIngestion->product_id === (int) $lockedProduct->id
                    && (int) $lockedIngestion->course_lesson_id === (int) $lockedLesson->id
                    && (int) $lockedIngestion->user_id === (int) $request->user()->id
                    && hash_equals((string) $lockedIngestion->sha256, (string) $inspection['checksum']),
                    409,
                    'مرحله تأیید منبع قبلاً مصرف یا منقضی شده است.'
                );

                $file = StoreProductFile::create([
                    'product_id' => $lockedProduct->id,
                    'course_lesson_id' => $lockedLesson->id,
                    'purpose' => 'lesson_resource',
                    'version' => $lockedProduct->version,
                    'disk' => 'private',
                    'path' => $finalPath,
                    'original_name' => $lockedIngestion->original_name,
                    'mime' => $inspection['mime'],
                    'size_bytes' => $inspection['size'],
                    'sha256' => $inspection['checksum'],
                    'is_primary' => false,
                    'is_active' => true,
                    'scan_status' => 'clean',
                    'scan_engine' => $inspection['scan'],
                    'scanned_at' => now(),
                ]);

                $documentId = DB::table('store_product_documents')->insertGetId([
                    'product_id' => $lockedProduct->id,
                    'product_file_id' => $file->id,
                    'source_sha256' => $file->sha256,
                    'processing_status' => $processingStatus,
                    'detected_type' => $inspection['detected_type'] ?? null,
                    'page_count' => $inspection['page_count'] ?? null,
                    'character_count' => 0,
                    'word_count' => 0,
                    'failure_code' => $processingStatus === 'awaiting_provider' ? 'ai_processing_size_limit' : null,
                    'structure_json' => json_encode([
                        'preflight' => $inspection,
                        'audio' => ['status' => 'not_applicable'],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $lockedIngestion->update([
                    'status' => 'uploaded',
                    'confirmed_at' => now(),
                    'staging_path' => $finalPath,
                ]);

                $audit->record(
                    'store.course.resource_uploaded',
                    (int) $request->user()->id,
                    null,
                    'store_product',
                    (int) $lockedProduct->id,
                    [
                        'lesson_id' => (int) $lockedLesson->id,
                        'file_id' => (int) $file->id,
                        'document_id' => (int) $documentId,
                        'mime' => $file->mime,
                        'size_bytes' => (int) $file->size_bytes,
                        'sha256' => $file->sha256,
                        'security_engine' => $inspection['scan'],
                        'processing_status' => $processingStatus,
                    ],
                    'admin_console'
                );

                if ($processingStatus === 'pending') {
                    DB::afterCommit(function () use (&$documentId): void {
                        try {
                            ProcessProductDocument::dispatch((int) $documentId);
                        } catch (Throwable $exception) {
                            DB::table('store_product_documents')->where('id', $documentId)->update([
                                'processing_status' => 'failed',
                                'failure_code' => 'queue_dispatch_failed',
                                'updated_at' => now(),
                            ]);
                            report($exception);
                        }
                    });
                }
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($finalPath);
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'document_id' => $documentId,
            'processing_status' => $processingStatus,
            'message' => $processingStatus === 'pending'
                ? 'منبع پس از تأیید نهایی ذخیره شد و پردازش آن در صف قرار گرفت.'
                : 'منبع امن ذخیره شد؛ حجم آن از سقف پردازش فعلی بیشتر است و تا آماده‌شدن پردازش دسته‌ای تحلیل نمی‌شود.',
        ]);
    }

    public function cancel(Request $request, StoreProduct $product, StoreCourseLesson $lesson, string $ingestionId)
    {
        $this->assertLessonBelongsToProduct($product, $lesson);
        $ingestion = StoreProductIngestion::query()
            ->whereKey($ingestionId)
            ->where('product_id', $product->id)
            ->where('course_lesson_id', $lesson->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'inspected')
            ->firstOrFail();

        Storage::disk($ingestion->staging_disk ?: 'private')->delete($ingestion->staging_path);
        $ingestion->delete();

        return response()->json(['success' => true]);
    }

    private function assertLessonBelongsToProduct(StoreProduct $product, StoreCourseLesson $lesson): void
    {
        abort_unless(
            $product->type === 'course'
            && $lesson->section()->whereHas('course', fn ($query) => $query->where('product_id', $product->id))->exists(),
            404
        );
    }

    private function pruneExpiredStaging(int $productId): void
    {
        $expired = StoreProductIngestion::query()
            ->where('product_id', $productId)
            ->where('status', 'inspected')
            ->where('expires_at', '<', now())
            ->limit(30)
            ->get();

        foreach ($expired as $ingestion) {
            Storage::disk($ingestion->staging_disk ?: 'private')->delete($ingestion->staging_path);
            $ingestion->delete();
        }
    }

    private function extension(string $name): string
    {
        $name = strtolower(basename(str_replace(chr(92), '/', $name)));

        return str_ends_with($name, '.tar.gz')
            ? 'tar.gz'
            : (str_ends_with($name, '.tar.bz2') ? 'tar.bz2' : strtolower(pathinfo($name, PATHINFO_EXTENSION)));
    }
}
