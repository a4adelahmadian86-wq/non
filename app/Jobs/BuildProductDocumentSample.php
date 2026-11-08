<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentSample;
use App\Models\StoreProductPreview;
use App\Models\StoreProductUploadEvent;
use App\Services\ProductFileStorageSelector;
use App\Services\ProductSupervisorClient;
use App\Http\Controllers\StoreController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BuildProductDocumentSample implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $documentId)
    {
    }

    public function backoff(): array
    {
        return [180, 600];
    }

    public function handle(ProductSupervisorClient $supervisor, ProductFileStorageSelector $storageSelector): void
    {
        $document = StoreProductDocument::query()->with('product')->findOrFail($this->documentId);
        $existing = StoreProductDocumentSample::query()->where('document_id', $document->id)->first();
        if ($existing && $existing->state === 'ready') return;

        $extension = strtolower((string) $document->extension);
        $sourceDisk = Storage::disk($document->storage_disk);
        if (! $sourceDisk->exists($document->storage_path)) {
            throw new RuntimeException('document_sample_source_missing');
        }

        $selectedPages = array_values(array_unique(array_filter(
            (array) data_get($document->content_manifest, 'sample_page_candidates', []),
            fn ($page) => is_int($page) && $page > 0 && (! $document->page_count || $page <= $document->page_count)
        )));
        sort($selectedPages);

        $sampleBytes = null;
        $sampleMime = 'application/pdf';
        $sampleKind = 'pdf';
        $sampleFormat = 'pdf';

        if (in_array($extension, ['pdf', 'docx'], true)) {
            if ($selectedPages === []) {
                StoreProductDocumentSample::updateOrCreate(
                    ['document_id' => $document->id],
                    [
                        'storage_disk' => 'private',
                        'storage_path' => 'store/product-samples/pending/'.$document->id.'.pdf',
                        'mime' => 'application/pdf',
                        'selected_pages' => [],
                        'sample_format' => 'pdf',
                        'state' => 'awaiting_ai_page_selection',
                    ]
                );
                $this->recordEvent($document, 'pending', 'انتخاب صفحات نمونه در انتظار تأیید هوش مصنوعی است', 'تا وقتی مدل صفحات واقعی را از محتوای مطالعه‌شده انتخاب نکند، نمونه ساخته نمی‌شود.');
                return;
            }

            $sourceStream = $sourceDisk->readStream($document->storage_path);
            if (! is_resource($sourceStream)) throw new RuntimeException('document_sample_source_stream_unavailable');
            $pdfStream = $sourceStream;
            $convertedStream = null;
            $sampleFilename = $document->original_name;

            try {
                if ($extension === 'docx') {
                    $sourceBytes = stream_get_contents($sourceStream);
                    if (! is_string($sourceBytes) || $sourceBytes === '') throw new RuntimeException('document_sample_docx_read_failed');
                    $pdfBytes = app(StoreController::class)->prepareDocxPreview($sourceBytes);
                    if (! is_string($pdfBytes) || ! str_starts_with($pdfBytes, '%PDF-')) throw new RuntimeException('document_sample_docx_conversion_failed');
                    $convertedStream = fopen('php://temp', 'w+b');
                    if (! is_resource($convertedStream) || fwrite($convertedStream, $pdfBytes) !== strlen($pdfBytes)) {
                        throw new RuntimeException('document_sample_docx_temp_failed');
                    }
                    rewind($convertedStream);
                    $pdfStream = $convertedStream;
                    $sampleFilename = pathinfo($document->original_name, PATHINFO_FILENAME).'.pdf';
                }
                $sampleBytes = $supervisor->createPdfSample($pdfStream, $sampleFilename, $selectedPages);
            } finally {
                if (is_resource($convertedStream)) fclose($convertedStream);
                fclose($sourceStream);
            }
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $sampleMime = $document->detected_mime ?: 'application/octet-stream';
            $sampleKind = 'image';
            $sampleFormat = $extension;
            $sampleStream = $sourceDisk->readStream($document->storage_path);
            if (! is_resource($sampleStream)) throw new RuntimeException('document_sample_source_stream_unavailable');
            $sampleBytes = stream_get_contents($sampleStream);
            fclose($sampleStream);
            if (! is_string($sampleBytes) || $sampleBytes === '') throw new RuntimeException('document_sample_image_read_failed');
        } else {
            StoreProductDocumentSample::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'storage_disk' => 'private',
                    'storage_path' => 'store/product-samples/pending/'.$document->id.'.bin',
                    'mime' => $document->detected_mime ?: 'application/octet-stream',
                    'selected_pages' => $selectedPages,
                    'sample_format' => $extension,
                    'state' => 'awaiting_converter',
                ]
            );
            $this->recordEvent($document, 'pending', 'مبدل نمونه این فرمت هنوز آماده نیست', 'فایل اصلی حفظ شده و هیچ نمونه ناقصی تولید نمی‌شود.');
            return;
        }

        if (! is_string($sampleBytes) || $sampleBytes === '') {
            throw new RuntimeException('document_sample_empty_output');
        }

        $diskName = $storageSelector->select($document->product, ['kind' => $sampleKind]);
        $path = 'store/product-samples/'.$document->product_id.'/'.$document->id.'-v'.$document->content_version.'.'.($sampleKind === 'image' ? $sampleFormat : 'pdf');
        $targetDisk = Storage::disk($diskName);
        if (! $targetDisk->put($path, $sampleBytes)) {
            throw new RuntimeException('document_sample_storage_failed');
        }

        try {
            DB::transaction(function () use ($document, $diskName, $path, $sampleMime, $selectedPages, $sampleFormat, $sampleKind) {
                StoreProductDocumentSample::updateOrCreate(
                    ['document_id' => $document->id],
                    [
                        'storage_disk' => $diskName,
                        'storage_path' => $path,
                        'mime' => $sampleMime,
                        'selected_pages' => $selectedPages,
                        'sample_format' => $sampleFormat,
                        'state' => 'ready',
                        'generated_at' => now(),
                    ]
                );

                $preview = StoreProductPreview::query()
                    ->where('product_id', $document->product_id)
                    ->where('disk', $diskName)
                    ->where('path', $path)
                    ->first();

                if (! $preview) {
                    StoreProductPreview::create([
                        'product_id' => $document->product_id,
                        'disk' => $diskName,
                        'path' => $path,
                        'mime' => $sampleMime,
                        'page_number' => 1,
                        'kind' => $sampleKind,
                        'access_level' => 'sample',
                        'watermarked' => true,
                        'is_active' => true,
                        'sort_order' => (int) StoreProductPreview::query()->where('product_id', $document->product_id)->max('sort_order') + 1,
                    ]);
                }

                $manifest = (array) $document->content_manifest;
                $manifest['sample_status'] = 'ready';
                $manifest['sample_pages'] = $selectedPages;
                $manifest['sample_generated_once'] = true;
                $document->update(['content_manifest' => $manifest]);
            });
        } catch (Throwable $exception) {
            $targetDisk->delete($path);
            throw $exception;
        }

        $this->recordEvent($document, 'completed', 'فایل نمونه یک‌باره ساخته شد', 'صفحات انتخاب‌شده توسط هوش مصنوعی با ابزار مستقل فراست از فایل منبع جدا شدند؛ مدل هیچ فایلی تولید نکرد.', [
            'pages' => $selectedPages,
            'format' => $sampleFormat,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $document = StoreProductDocument::query()->find($this->documentId);
        if (! $document) return;
        $sample = StoreProductDocumentSample::query()->where('document_id', $document->id)->first();
        if ($sample && $sample->state !== 'ready') $sample->update(['state' => 'failed']);
        $this->recordEvent($document, 'failed', 'ساخت فایل نمونه ناموفق بود', 'فایل اصلی دست‌نخورده باقی ماند؛ پس از اصلاح مبدل می‌توان دوباره تلاش کرد.');
    }

    private function recordEvent(StoreProductDocument $document, string $state, string $title, string $detail, array $metadata = []): void
    {
        if (! $document->upload_session_id) return;
        StoreProductUploadEvent::create([
            'upload_session_id' => $document->upload_session_id,
            'stage' => 'sample_generation',
            'state' => $state,
            'title' => $title,
            'detail' => $detail,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
