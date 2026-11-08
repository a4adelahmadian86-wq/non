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
        if ($existing && $existing->state === 'ready' && (int) $existing->content_version === (int) $document->content_version) return;

        $extension = strtolower((string) $document->extension);
        $readerDiskName = $document->reader_asset_disk ?: $document->storage_disk;
        $readerPath = $document->reader_asset_path ?: $document->storage_path;
        $readerMime = $document->reader_asset_mime ?: ($document->detected_mime ?: 'application/octet-stream');
        $readerKind = $document->reader_asset_kind;
        $sourceDisk = Storage::disk($readerDiskName);
        if (! $sourceDisk->exists($readerPath)) {
            throw new RuntimeException('document_sample_source_missing');
        }

        if (($document->product->download_policy ?? 'signed') === 'reader_only' && in_array($readerKind, ['pdf', 'image', 'audio', 'video'], true)) {
            $fullPreview = StoreProductPreview::query()
                ->where('document_id', $document->id)
                ->where('access_level', 'full')
                ->first();
            $fullAttributes = [
                'product_id' => $document->product_id,
                'document_id' => $document->id,
                'disk' => $readerDiskName,
                'path' => $readerPath,
                'mime' => $readerMime,
                'page_number' => 1,
                'kind' => $readerKind,
                'access_level' => 'full',
                'watermarked' => true,
                'is_active' => true,
                'sort_order' => (int) StoreProductPreview::query()->where('product_id', $document->product_id)->max('sort_order') + 1,
            ];
            if ($fullPreview) $fullPreview->update($fullAttributes);
            else StoreProductPreview::create($fullAttributes);
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

        if ($readerKind === 'pdf') {
            if ($selectedPages === []) {
                StoreProductDocumentSample::updateOrCreate(
                    ['document_id' => $document->id],
                    [
                        'storage_disk' => 'private',
                        'storage_path' => 'store/product-samples/pending/'.$document->id.'.pdf',
                        'mime' => 'application/pdf',
                        'selected_pages' => [],
                        'sample_format' => 'pdf',
                        'content_version' => $document->content_version,
                        'state' => 'awaiting_ai_page_selection',
                    ]
                );
                $this->recordEvent($document, 'pending', 'انتخاب صفحات نمونه در انتظار تأیید هوش مصنوعی است', 'تا وقتی مدل صفحات واقعی را از محتوای مطالعه‌شده انتخاب نکند، نمونه ساخته نمی‌شود.');
                return;
            }

            $pdfStream = $sourceDisk->readStream($readerPath);
            if (! is_resource($pdfStream)) throw new RuntimeException('document_sample_source_stream_unavailable');
            try {
                $sampleBytes = $supervisor->createPdfSample($pdfStream, pathinfo($document->original_name, PATHINFO_FILENAME).'.pdf', $selectedPages);
            } finally {
                fclose($pdfStream);
            }
        } elseif ($readerKind === 'image') {
            $sampleMime = $readerMime;
            $sampleKind = 'image';
            $sampleFormat = $extension;
            $sampleStream = $sourceDisk->readStream($readerPath);
            if (! is_resource($sampleStream)) throw new RuntimeException('document_sample_source_stream_unavailable');
            $sampleBytes = stream_get_contents($sampleStream);
            fclose($sampleStream);
            if (! is_string($sampleBytes) || $sampleBytes === '') throw new RuntimeException('document_sample_image_read_failed');
        } elseif (in_array($readerKind, ['audio', 'video'], true)) {
            StoreProductDocumentSample::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'storage_disk' => $readerDiskName,
                    'storage_path' => $readerPath,
                    'mime' => $readerMime,
                    'selected_pages' => [],
                    'sample_format' => $readerKind,
                    'content_version' => $document->content_version,
                    'state' => 'awaiting_clip_builder',
                ]
            );
            $this->recordEvent($document, 'pending', 'ساخت کلیپ نمونه در صف توسعه است', 'فایل اصلی برای خوانشگر خصوصی آماده است؛ نسخه کوتاه نمونه هنوز ساخته نشده است.');
            return;
        } else {
            StoreProductDocumentSample::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'storage_disk' => 'private',
                    'storage_path' => 'store/product-samples/pending/'.$document->id.'.bin',
                    'mime' => $readerMime,
                    'selected_pages' => $selectedPages,
                    'sample_format' => $extension,
                    'content_version' => $document->content_version,
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
                        'content_version' => $document->content_version,
                        'state' => 'ready',
                        'generated_at' => now(),
                    ]
                );

                $preview = StoreProductPreview::query()
                    ->where('document_id', $document->id)
                    ->where('access_level', 'sample')
                    ->first();

                $previewAttributes = [
                    'product_id' => $document->product_id,
                    'document_id' => $document->id,
                    'disk' => $diskName,
                    'path' => $path,
                    'mime' => $sampleMime,
                    'page_number' => 1,
                    'kind' => $sampleKind,
                    'access_level' => 'sample',
                    'watermarked' => true,
                    'is_active' => true,
                    'sort_order' => (int) StoreProductPreview::query()->where('product_id', $document->product_id)->max('sort_order') + 1,
                ];
                if ($preview) $preview->update($previewAttributes);
                else StoreProductPreview::create($previewAttributes);

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
