<?php

namespace App\Services;

use App\Jobs\ProcessProductDocument;
use App\Models\StoreProductDocument;
use App\Models\StoreProductFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductDocumentRetryService
{
    private const RETRYABLE_STATUSES = ['failed', 'stale', 'awaiting_provider'];

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly ProductSupervisorClient $supervisor,
    ) {
    }

    public function retry(int $documentId, ?int $actorId): void
    {
        if (! $this->supervisor->configured()) {
            $this->reject('سرویس ناظر و استخراج محصول هنوز پیکربندی نشده است؛ ابتدا اتصال زیرساخت را کامل کنید.');
        }

        $document = StoreProductDocument::query()->with('file')->findOrFail($documentId);
        $file = $document->file;

        $this->assertRetryable($document, $file);
        $disk = Storage::disk('private');
        if (! $disk->exists($file->path)) {
            $this->reject('فایل اصلی در فضای خصوصی پیدا نشد؛ بارگذاری مجدد لازم است.');
        }

        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            $this->reject('خواندن امن فایل اصلی ممکن نیست.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actualHash = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals((string) $document->source_sha256, $actualHash)
            || ! hash_equals((string) $file->sha256, $actualHash)) {
            $this->reject('اثر انگشت فایل با نسخه ثبت‌شده یکسان نیست؛ پردازش مجدد متوقف شد.');
        }

        DB::transaction(function () use ($document, $file, $actorId, $actualHash): void {
            $locked = StoreProductDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $lockedFile = StoreProductFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();

            $this->assertRetryable($locked, $lockedFile);
            if (! hash_equals((string) $locked->source_sha256, $actualHash)
                || ! hash_equals((string) $lockedFile->sha256, $actualHash)
                || $lockedFile->path !== $file->path
                || ($lockedFile->disk ?: 'private') !== 'private') {
                $this->reject('نسخه فایل هنگام آماده‌سازی تغییر کرده است؛ عملیات دوباره بررسی شود.');
            }

            $locked->update([
                'processing_status' => 'pending',
                'failure_code' => null,
                'processed_at' => null,
            ]);

            $this->audit->record(
                'store.product_document.manual_retry_queued',
                $actorId,
                null,
                'store_product_document',
                (int) $locked->id,
                [
                    'product_id' => (int) $locked->product_id,
                    'product_file_id' => (int) $lockedFile->id,
                    'source_sha256' => $actualHash,
                    'previous_status' => $document->processing_status,
                ],
                'admin_console'
            );

            DB::afterCommit(fn () => ProcessProductDocument::dispatch((int) $locked->id));
        }, 3);
    }

    private function assertRetryable(StoreProductDocument $document, ?StoreProductFile $file): void
    {
        if (! in_array($document->processing_status, self::RETRYABLE_STATUSES, true)) {
            $this->reject('این سند در وضعیت قابل تلاش مجدد نیست؛ وضعیت‌های در صف یا در حال پردازش دست‌کاری نمی‌شوند.');
        }

        if (! $file
            || (int) $file->product_id !== (int) $document->product_id
            || ! in_array($file->purpose, ['product_download', 'lesson_resource'], true)
            || ! $file->is_active
            || $file->scan_status !== 'clean'
            || ($file->disk ?: 'private') !== 'private'
            || ! filled($file->sha256)
            || ! hash_equals((string) $document->source_sha256, (string) $file->sha256)) {
            $this->reject('فایل فعال، سالم و هم‌نسخه برای این سند تأیید نشد.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
