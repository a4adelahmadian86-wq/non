<?php

namespace App\Http\Controllers;

use App\Models\StoreProduct;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use App\Models\StoreProductUploadEvent;
use App\Models\StoreProductUploadSession;
use App\Services\AuditEventService;
use App\Services\ProductFilePreflightScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminProductFileIngestionController extends Controller
{
    public function preflight(Request $request, StoreProduct $product, ProductFilePreflightScanner $scanner)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، فایل‌ها باید از بخش منابع جلسه بارگذاری شوند.');

        $request->validate([
            'file' => 'required|file|max:51200',
            'is_primary' => 'nullable|boolean',
        ]);

        $upload = $request->file('file');
        $originalName = basename(str_replace('\\', '/', (string) $upload->getClientOriginalName()));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $quarantinePath = $upload->storeAs(
            'store/quarantine/product-ingestion/'.$product->id,
            Str::uuid().'.'.$extension,
            'private'
        );

        try {
            $disk = Storage::disk('private');
            $inspection = $scanner->inspect($disk->path($quarantinePath), $originalName, $upload->getClientMimeType());
            $sha256 = hash_file('sha256', $disk->path($quarantinePath));
            if (! is_string($sha256)) {
                throw new RuntimeException('preflight_checksum_failed');
            }

            $token = Str::random(64);
            $session = DB::transaction(function () use ($product, $originalName, $inspection, $sha256, $quarantinePath, $token) {
                StoreProductUploadSession::query()
                    ->where('product_id', $product->id)
                    ->whereIn('state', ['awaiting_confirmation', 'preflight_complete'])
                    ->where('expires_at', '<', now())
                    ->delete();

                $session = StoreProductUploadSession::create([
                    'product_id' => $product->id,
                    'token_hash' => hash('sha256', $token),
                    'state' => 'awaiting_confirmation',
                    'original_name' => $originalName,
                    'detected_mime' => $inspection['detected_mime'] ?? null,
                    'size_bytes' => $inspection['size_bytes'],
                    'sha256' => $sha256,
                    'quarantine_disk' => 'private',
                    'quarantine_path' => $quarantinePath,
                    'inspection' => $inspection,
                    'security_report' => $inspection['security'],
                    'expires_at' => now()->addHours(2),
                    'created_by' => auth()->id(),
                ]);

                $this->event($session, 'preflight', 'completed', 'بررسی اولیه سایت انجام شد', 'نوع واقعی، حجم و ساختار فایل بررسی شد.', [
                    'kind' => $inspection['kind'],
                    'size_bytes' => $inspection['size_bytes'],
                ]);

                return $session;
            });

            return response()->json([
                'ok' => true,
                'token' => $token,
                'session_id' => $session->id,
                'expires_at' => $session->expires_at?->toISOString(),
                'inspection' => $inspection,
                'timeline' => $session->events()->get(['stage', 'state', 'title', 'detail', 'created_at']),
            ], 201);
        } catch (Throwable $exception) {
            Storage::disk('private')->delete($quarantinePath);
            $code = $exception instanceof RuntimeException ? $exception->getMessage() : 'preflight_failed';
            report($exception);

            return response()->json([
                'ok' => false,
                'code' => Str::before($code, ':'),
                'message' => $this->publicError($code),
            ], 422);
        }
    }

    public function confirm(Request $request, StoreProduct $product, string $token, ProductFilePreflightScanner $scanner)
    {
        abort_unless($product->type !== 'course', 422, 'برای دوره، فایل‌ها باید از بخش منابع جلسه بارگذاری شوند.');
        $session = StoreProductUploadSession::query()
            ->where('product_id', $product->id)
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        if ($session->state === 'completed') {
            return response()->json(['ok' => true, 'already_completed' => true, 'message' => 'این فایل قبلاً ثبت شده است.']);
        }
        abort_unless($session->state === 'awaiting_confirmation' && $session->expires_at?->isFuture(), 410, 'مهلت تأیید فایل تمام شده است؛ فایل را دوباره انتخاب کنید.');

        $disk = Storage::disk($session->quarantine_disk ?: 'private');
        abort_unless($disk->exists($session->quarantine_path), 410, 'فایل موقت دیگر در دسترس نیست؛ دوباره بارگذاری کنید.');

        $this->event($session, 'security_scan', 'running', 'اسکن امنیتی اختصاصی فراست', 'در حال بررسی مجدد فایل، امضاها و ساختار داخلی آن.');
        try {
            $inspection = $scanner->inspect($disk->path($session->quarantine_path), $session->original_name, $session->detected_mime);
            $actualHash = hash_file('sha256', $disk->path($session->quarantine_path));
            abort_unless(is_string($actualHash) && hash_equals((string) $session->sha256, $actualHash), 409, 'فایل پس از بررسی اولیه تغییر کرده است؛ لطفاً دوباره انتخاب کنید.');

            $this->event($session, 'security_scan', 'completed', 'بررسی ساختاری فراست موفق بود', 'فایل از کنترل‌های ساختاری و محدودیت‌های امنیتی عبور کرد.', [
                'engine' => ProductFilePreflightScanner::ENGINE,
                'sha256' => $actualHash,
            ]);

            $extension = strtolower(pathinfo($session->original_name, PATHINFO_EXTENSION));
            $finalPath = 'store/products/'.$product->id.'/'.Str::uuid().($extension ? '.'.$extension : '');
            $targetDisk = Storage::disk('private');
            $this->event($session, 'storage_selection', 'completed', 'فضای ذخیره‌سازی انتخاب شد', 'مخزن خصوصی مناسب این نوع فایل انتخاب شد.', [
                'storage_class' => 'private',
            ]);

            if (! $targetDisk->move($session->quarantine_path, $finalPath)) {
                throw new RuntimeException('product_file_storage_move_failed');
            }

            $isPrimary = $request->boolean('is_primary', true);
            try {
                $result = DB::transaction(function () use ($session, $product, $inspection, $actualHash, $finalPath, $isPrimary) {
                    $lockedSession = StoreProductUploadSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                    if ($lockedSession->state === 'completed') {
                        throw new RuntimeException('upload_session_already_completed');
                    }

                    $lockedProduct = StoreProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                    if ($isPrimary) {
                        $lockedProduct->files()->where('purpose', 'product_download')->update(['is_primary' => false]);
                    }

                    $file = StoreProductFile::create([
                        'product_id' => $lockedProduct->id,
                        'purpose' => 'product_download',
                        'version' => $lockedProduct->version,
                        'disk' => 'private',
                        'path' => $finalPath,
                        'original_name' => $lockedSession->original_name,
                        'mime' => $inspection['detected_mime'] ?? $lockedSession->detected_mime,
                        'size_bytes' => $lockedSession->size_bytes,
                        'sha256' => $actualHash,
                        'is_primary' => $isPrimary,
                        'is_active' => true,
                        'scan_status' => 'clean',
                        'scan_engine' => ProductFilePreflightScanner::ENGINE,
                        'scanned_at' => now(),
                    ]);

                    $document = StoreProductDocument::create([
                        'product_id' => $lockedProduct->id,
                        'product_file_id' => $file->id,
                        'source_sha256' => $actualHash,
                        'original_name' => $lockedSession->original_name,
                        'detected_mime' => $inspection['detected_mime'] ?? null,
                        'extension' => strtolower(pathinfo($lockedSession->original_name, PATHINFO_EXTENSION)),
                        'size_bytes' => $lockedSession->size_bytes,
                        'storage_disk' => 'private',
                        'storage_path' => $finalPath,
                        'processing_state' => 'pending_study',
                        'page_count' => data_get($inspection, 'page_count'),
                        'duration_seconds' => data_get($inspection, 'duration_seconds'),
                        'technical_metadata' => $inspection,
                        'content_manifest' => [
                            'source_sha256' => $actualHash,
                            'manifest_version' => 1,
                            'study_status' => 'pending',
                            'sample_status' => 'not_generated',
                            'audio_status' => 'not_generated',
                        ],
                    ]);

                    $lockedSession->update([
                        'state' => 'completed',
                        'confirmed_at' => now(),
                        'quarantine_path' => $finalPath,
                        'inspection' => $inspection,
                    ]);

                    $this->event($lockedSession, 'storage_upload', 'completed', 'فایل اصلی در مخزن خصوصی ثبت شد', 'نسخه اصلی با شناسه و هش یکتا ذخیره شد.');
                    $this->event($lockedSession, 'content_study', 'pending', 'پرونده محتوایی ساخته شد', 'مرحله مطالعه هوشمند پس از اتصال پردازشگر محتوایی آغاز می‌شود.', [
                        'document_id' => $document->id,
                    ]);

                    app(AuditEventService::class)->record(
                        'store.product.file_ingested',
                        auth()->id(),
                        null,
                        'store_product',
                        $lockedProduct->id,
                        [
                            'file_id' => $file->id,
                            'document_id' => $document->id,
                            'sha256' => $actualHash,
                            'scan_engine' => ProductFilePreflightScanner::ENGINE,
                        ],
                        'admin_console'
                    );

                    return ['file_id' => $file->id, 'document_id' => $document->id];
                });
            } catch (Throwable $exception) {
                $targetDisk->delete($finalPath);
                throw $exception;
            }

            return response()->json([
                'ok' => true,
                'message' => 'فایل تأیید و ذخیره شد. پرونده محتوایی برای مطالعه آماده است.',
                'file_id' => $result['file_id'],
                'document_id' => $result['document_id'],
                'timeline' => $session->fresh()->events()->get(['stage', 'state', 'title', 'detail', 'created_at']),
            ]);
        } catch (Throwable $exception) {
            $this->event($session, 'security_scan', 'failed', 'فرایند متوقف شد', $this->publicError($exception->getMessage()));
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => $this->publicError($exception->getMessage()),
            ], 422);
        }
    }

    public function cancel(StoreProduct $product, string $token)
    {
        $session = StoreProductUploadSession::query()
            ->where('product_id', $product->id)
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        if ($session->state !== 'completed') {
            Storage::disk($session->quarantine_disk ?: 'private')->delete($session->quarantine_path);
            $session->update(['state' => 'cancelled']);
        }

        return response()->json(['ok' => true]);
    }

    private function event(StoreProductUploadSession $session, string $stage, string $state, string $title, ?string $detail = null, array $metadata = []): void
    {
        StoreProductUploadEvent::create([
            'upload_session_id' => $session->id,
            'stage' => $stage,
            'state' => $state,
            'title' => $title,
            'detail' => $detail,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    private function publicError(string $code): string
    {
        return match (Str::before($code, ':')) {
            'preflight_file_unreadable' => 'خواندن فایل ممکن نشد.',
            'preflight_file_too_large' => 'حجم فایل از سقف ۵۰ مگابایت بیشتر است.',
            'preflight_empty_file' => 'فایل خالی است.',
            'preflight_extension_not_allowed' => 'این فرمت در فهرست فرمت‌های مجاز فراست نیست.',
            'preflight_executable_signature_rejected', 'preflight_dangerous_extension' => 'فایل اجرایی یا اسکریپت قابل بارگذاری نیست.',
            'preflight_archive_format_not_supported_for_safe_listing' => 'این نوع آرشیو هنوز امکان فهرست‌برداری امن داخلی ندارد.',
            'preflight_office_macros_not_allowed' => 'فایل آفیس دارای ماکرو قابل پذیرش نیست.',
            'preflight_archive_suspicious' => 'ساختار آرشیو مشکوک است و برای حفظ امنیت پذیرفته نشد.',
            'preflight_zip64_not_supported' => 'این آرشیو از ساختار ZIP64 استفاده می‌کند و فعلاً قابل بررسی کامل نیست.',
            default => 'بررسی فایل کامل نشد؛ فایل تغییر کرده یا ساختار آن قابل اعتماد نیست.',
        };
    }
}
