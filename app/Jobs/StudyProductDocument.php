<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentBlock;
use App\Models\StoreProductUploadEvent;
use App\Jobs\GenerateProductDocumentAudio;
use App\Services\AuditEventService;
use App\Services\ProductSupervisorClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StudyProductDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;

    public function __construct(public readonly int $documentId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProductSupervisorClient $supervisor): void
    {
        $document = StoreProductDocument::query()
            ->with(['product.category', 'product.tags', 'uploadSession'])
            ->findOrFail($this->documentId);

        if (in_array($document->processing_state, ['studied', 'extracted_only'], true)) {
            return;
        }

        $document->update(['processing_state' => 'studying']);
        $this->recordEvent($document, 'running', 'مطالعه محتوای فایل آغاز شد', 'استخراج و تحلیل محتوای قابل‌خواندن در حال انجام است.');

        $stream = Storage::disk($document->storage_disk)->readStream($document->storage_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('document_source_stream_unavailable');
        }

        try {
            $product = $document->product;
            $review = $supervisor->reviewFile($stream, $document->original_name, [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'primary_scan_status' => 'structural_clean',
                'category_name' => $product->category?->name,
                'tags_json' => json_encode($product->tags->pluck('name')->take(30)->values()->all(), JSON_UNESCAPED_UNICODE),
                'description' => mb_substr((string) $product->description, 0, 8000),
            ]);
        } finally {
            fclose($stream);
        }

        $inspection = (array) ($review['inspection'] ?? []);
        $blocks = is_array($review['content_blocks'] ?? null) ? array_slice($review['content_blocks'], 0, 2000) : [];
        if ($blocks === [] && filled($review['extracted_content'] ?? null)) {
            $blocks[] = [
                'sequence' => 1,
                'block_type' => 'paragraph',
                'page_number' => null,
                'text' => (string) $review['extracted_content'],
            ];
        }

        DB::transaction(function () use ($document, $review, $inspection, $blocks) {
            $locked = StoreProductDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->blocks()->delete();

            foreach ($blocks as $index => $block) {
                if (! is_array($block) || ! is_string($block['text'] ?? null) || trim($block['text']) === '') {
                    continue;
                }
                $text = mb_substr($block['text'], 0, 120000);
                $page = filter_var($block['page_number'] ?? null, FILTER_VALIDATE_INT);
                StoreProductDocumentBlock::create([
                    'document_id' => $locked->id,
                    'sequence' => (int) ($block['sequence'] ?? ($index + 1)),
                    'block_type' => mb_substr((string) ($block['block_type'] ?? 'paragraph'), 0, 32),
                    'page_number' => $page !== false && $page > 0 ? $page : null,
                    'text_content' => $text,
                    'metadata' => [
                        'source' => 'product-supervisor',
                        'redacted' => true,
                    ],
                    'content_sha256' => hash('sha256', $text),
                ]);
            }

            $profile = (array) ($review['content_profile'] ?? []);
            $status = (string) ($review['content_study_status'] ?? 'no_extractable_content');
            $processingState = $status === 'completed' ? 'studied' : ($status === 'extracted_only' ? 'extracted_only' : 'study_incomplete');
            $manifest = [
                'manifest_version' => 1,
                'source_sha256' => $locked->source_sha256,
                'study_status' => $status,
                'summary' => mb_substr((string) ($review['summary'] ?? ''), 0, 700),
                'outline' => array_slice(is_array($profile['outline'] ?? null) ? $profile['outline'] : [], 0, 100),
                'key_topics' => array_slice(is_array($profile['key_topics'] ?? null) ? $profile['key_topics'] : [], 0, 40),
                'grounded_facts' => array_slice(is_array($profile['grounded_facts'] ?? null) ? $profile['grounded_facts'] : [], 0, 100),
                'sample_page_candidates' => array_slice(is_array($profile['sample_page_candidates'] ?? null) ? $profile['sample_page_candidates'] : [], 0, 12),
                'provider_status' => (array) ($review['provider_status'] ?? []),
                'warnings' => array_slice(is_array($review['warnings'] ?? null) ? $review['warnings'] : [], 0, 30),
                'redacted_sensitive_items' => (int) ($review['redacted_sensitive_items'] ?? 0),
                'content_characters' => (int) data_get($inspection, 'extracted_characters', 0),
                'security_gate' => (string) ($review['security_gate'] ?? 'manual_scan_required'),
                'publication_authorized' => false,
                'sample_status' => 'not_generated',
                'audio_status' => 'not_generated',
            ];

            $locked->update([
                'processing_state' => $processingState,
                'page_count' => data_get($inspection, 'page_count', $locked->page_count),
                'technical_metadata' => array_merge((array) $locked->technical_metadata, ['supervisor_inspection' => $inspection]),
                'content_manifest' => $manifest,
                'content_studied_at' => now(),
            ]);

            $this->recordEvent($locked, $processingState === 'studied' ? 'completed' : 'partial',
                $processingState === 'studied' ? 'مطالعه محتوایی تکمیل شد' : 'استخراج محتوا انجام شد؛ تحلیل معنایی کامل نیست',
                'متن پالایش‌شده، ساختار بخش‌ها و اطلاعات مستند در پایگاه دانش محصول ذخیره شد.',
                ['block_count' => count($blocks), 'study_status' => $status]
            );

            app(AuditEventService::class)->record(
                'store.product.document_studied',
                null,
                null,
                'store_product',
                $locked->product_id,
                [
                    'document_id' => $locked->id,
                    'source_sha256' => $locked->source_sha256,
                    'processing_state' => $processingState,
                    'block_count' => count($blocks),
                ],
                'product_ingestion'
            );
        });

        GenerateProductDocumentAudio::dispatch($document->id);
    }

    public function failed(Throwable $exception): void
    {
        $document = StoreProductDocument::query()->find($this->documentId);
        if (! $document) return;

        $document->update(['processing_state' => 'study_failed']);
        $this->recordEvent($document, 'failed', 'مطالعه محتوایی ناموفق بود', 'پردازشگر نتوانست محتوای فایل را کامل کند؛ امکان تلاش مجدد وجود دارد.');
        Log::warning('Product document study failed', [
            'document_id' => $document->id,
            'exception' => class_basename($exception),
        ]);
    }

    private function recordEvent(StoreProductDocument $document, string $state, string $title, string $detail, array $metadata = []): void
    {
        if (! $document->upload_session_id) return;

        StoreProductUploadEvent::create([
            'upload_session_id' => $document->upload_session_id,
            'stage' => 'content_study',
            'state' => $state,
            'title' => $title,
            'detail' => $detail,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
