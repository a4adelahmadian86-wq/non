<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductPreview;
use App\Jobs\GenerateProductDocumentAudio;
use App\Services\ProductSupervisorClient;
use App\Services\StoreProductSampleBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessProductDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('documents');
    }

    public function handle(ProductSupervisorClient $supervisor, StoreProductSampleBuilder $sampleBuilder): void
    {
        $document = StoreProductDocument::query()
            ->with(['file.product.category', 'file.product.tags'])
            ->find($this->documentId);

        if (! $document || ! $document->file || ! $document->product) {
            return;
        }

        if (! $supervisor->configured()) {
            $document->update(['processing_status' => 'awaiting_provider', 'failure_code' => 'supervisor_not_configured']);
            return;
        }

        $file = $document->file;
        $product = $document->product;
        $disk = Storage::disk($file->disk ?: 'private');
        if (! $disk->exists($file->path)) {
            $document->update(['processing_status' => 'failed', 'failure_code' => 'source_file_missing']);
            return;
        }

        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            $document->update(['processing_status' => 'failed', 'failure_code' => 'source_stream_unavailable']);
            return;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $currentHash = hash_final($hash);
            rewind($stream);
            if (! hash_equals((string) $document->source_sha256, $currentHash)
                || ! hash_equals((string) $file->sha256, $currentHash)) {
                $document->update(['processing_status' => 'stale', 'failure_code' => 'source_checksum_changed']);
                return;
            }

            $document->update(['processing_status' => 'processing', 'failure_code' => null]);
            $product->loadMissing(['category', 'tags']);
            $reviewStream = $stream;
            $reviewFilename = $file->original_name;
            $sampleSourcePdf = null;
            $sourceExtension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
            if ($sourceExtension === 'docx') {
                $docxBytes = stream_get_contents($stream);
                if (! is_string($docxBytes) || $docxBytes === '') {
                    throw new \RuntimeException('product_docx_source_read_failed');
                }
                $readerPdfArtifact = $document->artifacts()->where('artifact_type', 'reader_pdf')->where('version', 1)->first();
                $readerPdfPath = $readerPdfArtifact?->path;
                $pdfDisk = Storage::disk($readerPdfArtifact?->disk ?: 'private');
                $pdfBytes = $readerPdfArtifact && $readerPdfPath && $pdfDisk->exists($readerPdfPath)
                    ? $pdfDisk->get($readerPdfPath)
                    : app(\App\Http\Controllers\StoreController::class)->prepareDocxPreview($docxBytes);
                if (! is_string($pdfBytes) || ! str_starts_with($pdfBytes, '%PDF-') || strlen($pdfBytes) > 25 * 1024 * 1024) {
                    throw new \RuntimeException('product_docx_conversion_invalid');
                }
                if (! $readerPdfArtifact || ! $readerPdfPath || ! $pdfDisk->exists($readerPdfPath)) {
                    $readerPdfPath = 'store/products/'.$product->id.'/artifacts/'.$document->source_sha256.'/reader-v1.pdf';
                    if (! Storage::disk('private')->put($readerPdfPath, $pdfBytes)) {
                        throw new \RuntimeException('product_docx_conversion_storage_failed');
                    }
                    $document->artifacts()->updateOrCreate(
                        ['artifact_type' => 'reader_pdf', 'version' => 1],
                        [
                            'disk' => 'private',
                            'path' => $readerPdfPath,
                            'mime' => 'application/pdf',
                            'size_bytes' => strlen($pdfBytes),
                            'sha256' => hash('sha256', $pdfBytes),
                            'metadata_json' => [
                                'source_sha256' => $document->source_sha256,
                                'conversion_engine' => 'phpword_dompdf',
                                'generated_once' => true,
                            ],
                        ]
                    );
                }
                $sampleSourcePdf = $pdfBytes;
                $reviewStream = fopen('php://temp', 'w+b');
                if (! is_resource($reviewStream)) {
                    throw new \RuntimeException('product_docx_review_stream_failed');
                }
                fwrite($reviewStream, $pdfBytes);
                rewind($reviewStream);
                $reviewFilename = pathinfo($file->original_name, PATHINFO_FILENAME).'.pdf';
            } else {
                if ($sourceExtension === 'pdf') {
                    $sampleSourcePdf = $disk->get($file->path);
                    if (! is_string($sampleSourcePdf) || ! str_starts_with($sampleSourcePdf, '%PDF-') || strlen($sampleSourcePdf) > 25 * 1024 * 1024) {
                        throw new \RuntimeException('product_pdf_sample_source_invalid');
                    }
                }
                rewind($stream);
            }

            $payload = $supervisor->reviewFile($reviewStream, $reviewFilename, [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'primary_scan_status' => 'structural_checks_passed',
                'allow_sample_generation' => (bool) $file->is_primary,
                'category_name' => $product->category?->name,
                'tags_json' => json_encode($product->tags->pluck('name')->take(30)->values()->all(), JSON_UNESCAPED_UNICODE),
                'description' => mb_substr((string) $product->description, 0, 8000),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Product document processing failed', [
                'document_id' => $this->documentId,
                'exception' => class_basename($exception),
            ]);
            throw $exception;
        } finally {
            if (isset($reviewStream) && is_resource($reviewStream) && $reviewStream !== $stream) {
                fclose($reviewStream);
            }
            fclose($stream);
        }

        $sample = null;
        $selectedSamplePages = array_values(array_filter((array) ($payload['sample_page_numbers'] ?? []), static fn ($page) => is_int($page) || (is_string($page) && ctype_digit($page))));
        if ($file->is_primary && is_string($sampleSourcePdf) && $sampleSourcePdf !== '' && $selectedSamplePages !== []) {
            try {
                $sample = $sampleBuilder->build($sampleSourcePdf, $selectedSamplePages, 3, 8 * 1024 * 1024);
            } catch (Throwable) {
                $payload['warnings'] = array_values(array_merge((array) ($payload['warnings'] ?? []), [
                    'ساخت نسخه نمونه در سایت انجام نشد؛ فایل اصلی و نتیجه مطالعه بدون تغییر نگهداری شدند.',
                ]));
            }
        }

        $chunks = is_array($payload['content_chunks'] ?? null) ? array_slice($payload['content_chunks'], 0, 120) : [];
        $totalCharacters = 0;
        $totalWords = 0;

        DB::transaction(function () use ($document, $file, $product, $sourceExtension, $payload, $chunks, $sample, &$totalCharacters, &$totalWords) {
            $locked = StoreProductDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->chunks()->delete();
            $locked->facts()->delete();

            foreach ($chunks as $index => $chunk) {
                if (! is_array($chunk)) continue;
                $text = mb_substr((string) ($chunk['content_text'] ?? ''), 0, 1800);
                if ($text === '') continue;
                $locator = is_array($chunk['locator'] ?? null) ? $chunk['locator'] : [];
                $chunkHash = (string) ($chunk['content_sha256'] ?? hash('sha256', $text));
                $locked->chunks()->create([
                    'chunk_index' => (int) $index,
                    'chunk_type' => preg_replace('/[^a-z0-9_]/i', '', (string) ($chunk['chunk_type'] ?? 'text')) ?: 'text',
                    'locator_json' => $locator,
                    'content_text' => $text,
                    'content_sha256' => strlen($chunkHash) === 64 ? $chunkHash : hash('sha256', $text),
                    'metadata_json' => [
                        'redacted_sensitive_items' => (int) ($chunk['redacted_sensitive_items'] ?? 0),
                    ],
                ]);
                $totalCharacters += mb_strlen($text);
                $totalWords += preg_match_all('/[\p{L}\p{N}]+/u', $text);
            }

            $facts = is_array($payload['facts'] ?? null) ? array_slice($payload['facts'], 0, 100) : [];
            $storedFactCount = 0;
            $allowedFactTypes = ['definition','concept','quantity','date','feature','requirement','warning','other'];
            foreach ($facts as $fact) {
                if (! is_array($fact)) continue;
                $factKey = mb_substr(trim((string) ($fact['fact_key'] ?? '')), 0, 160);
                $factValue = mb_substr(trim((string) ($fact['fact_value'] ?? '')), 0, 1000);
                $evidence = mb_substr(trim((string) ($fact['evidence_text'] ?? '')), 0, 700);
                if ($factKey === '' || $factValue === '' || mb_strlen($evidence) < 6) continue;

                $normalizedEvidence = mb_strtolower((string) preg_replace('/\\s+/u', ' ', $evidence));
                $matchedChunk = null;
                foreach ($chunks as $sourceChunk) {
                    if (! is_array($sourceChunk)) continue;
                    $sourceText = mb_strtolower((string) preg_replace('/\\s+/u', ' ', (string) ($sourceChunk['content_text'] ?? '')));
                    if ($normalizedEvidence !== '' && str_contains($sourceText, $normalizedEvidence)) {
                        $matchedChunk = $sourceChunk;
                        break;
                    }
                }
                if (! is_array($matchedChunk)) continue;

                $factType = strtolower((string) ($fact['fact_type'] ?? 'other'));
                $factType = in_array($factType, $allowedFactTypes, true) ? $factType : 'other';
                $locked->facts()->create([
                    'source_sha256' => $locked->source_sha256,
                    'fact_type' => $factType,
                    'fact_key' => $factKey,
                    'fact_value' => $factValue,
                    'evidence_text' => $evidence,
                    'locator_json' => is_array($matchedChunk['locator'] ?? null) ? $matchedChunk['locator'] : [],
                    'verification_status' => 'evidence_backed',
                ]);
                $storedFactCount++;
            }

            $inspection = is_array($payload['inspection'] ?? null) ? $payload['inspection'] : [];
            $pageBuild = app(\App\Services\StoreProductDocumentPageBuilder::class)->build($chunks, $inspection);
            $structure = (array) ($locked->structure_json ?? []);
            $locked->pages()->delete();
            foreach ($pageBuild['pages'] as $page) {
                $locked->pages()->create($page);
            }
            foreach ($pageBuild['chunk_locators'] as $chunkIndex => $locator) {
                $locked->chunks()->where('chunk_index', (int) $chunkIndex)->update(['locator_json' => $locator]);
            }

            $documentHtmlPreviews = StoreProductPreview::query()
                ->where('product_id', $locked->product_id)
                ->whereIn('kind', ['document_html', 'image'])
                ->whereIn('access_level', ['sample', 'full'])
                ->where('source_sha256', $locked->source_sha256);
            $documentHtmlPreviews->update(['is_active' => false]);

            $storedPages = $locked->pages()->get();
            if ($file->is_primary && ! in_array($sourceExtension, ['pdf', 'docx'], true)) {
                $samplePageNumbers = array_values(array_unique(array_filter(
                    array_map('intval', (array) ($payload['sample_page_numbers'] ?? [])),
                    static fn ($page) => $page > 0
                )));
                $sampleSheetNames = array_values(array_unique(array_filter(
                    array_map(static fn ($sheet) => is_string($sheet) ? trim($sheet) : '', (array) ($payload['sample_sheet_names'] ?? [])),
                    static fn ($sheet) => $sheet !== ''
                )));
                $isImageSource = in_array($sourceExtension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'], true);
                $samplePagesCreated = [];
                $sampleSourcePagesCreated = [];
                $sampleSheetsCreated = [];

                foreach ($storedPages as $page) {
                    $sourcePageNumber = $sourceExtension === 'pptx'
                        ? (int) data_get($page->locator_json, 'slide', $page->page_number)
                        : (int) $page->page_number;
                    $sheetName = (string) data_get($page->locator_json, 'sheet', '');
                    $isSamplePage = in_array($sourcePageNumber, $samplePageNumbers, true);
                    if ($sourceExtension === 'xlsx') {
                        $isSamplePage = $sheetName !== '' && in_array($sheetName, $sampleSheetNames, true);
                    }

                    $basePreview = [
                        'disk' => $isImageSource ? ($file->disk ?: 'private') : 'private',
                        'path' => $isImageSource
                            ? $file->path
                            : 'store/products/'.$locked->product_id.'/documents/'.$locked->source_sha256.'/reader/page-'.$page->page_number.'.html',
                        'mime' => $isImageSource ? ($file->mime ?: 'image/jpeg') : 'text/html',
                        'page_number' => $page->page_number,
                        'source_page_count' => count($storedPages),
                        'included_page_count' => 1,
                        'kind' => $isImageSource ? 'image' : 'document_html',
                        'source_sha256' => $locked->source_sha256,
                        'watermarked' => true,
                        'is_active' => true,
                        'sort_order' => $page->page_number,
                    ];

                    if (($product->download_policy ?? 'signed') === 'reader_only') {
                        StoreProductPreview::query()->updateOrCreate(
                            ['product_id' => $locked->product_id, 'product_document_page_id' => $page->id, 'access_level' => 'full'],
                            array_merge($basePreview, ['product_document_page_id' => $page->id, 'access_level' => 'full'])
                        );
                    }

                    if ($isSamplePage) {
                        StoreProductPreview::query()->updateOrCreate(
                            ['product_id' => $locked->product_id, 'product_document_page_id' => $page->id, 'access_level' => 'sample'],
                            array_merge($basePreview, ['product_document_page_id' => $page->id, 'access_level' => 'sample'])
                        );
                        $samplePagesCreated[] = (int) $page->page_number;
                        if ($sourceExtension === 'xlsx') {
                            $sampleSheetsCreated[] = $sheetName;
                        } else {
                            $sampleSourcePagesCreated[] = $sourcePageNumber;
                        }
                    }
                }

                if ($samplePagesCreated !== []) {
                    $structure['sample'] = [
                        'selected_pages' => $sampleSourcePagesCreated,
                        'selected_sheets' => $sampleSheetsCreated,
                        'reader_pages' => $samplePagesCreated,
                        'source_page_count' => count($storedPages),
                        'included_page_count' => count($samplePagesCreated),
                        'generated_once' => true,
                        'generator' => $isImageSource ? 'source_image' : 'laravel_document_page_builder',
                    ];
                }
            }

            $structure['inspection'] = $inspection;
            $structure['reader'] = [
                'ready' => $pageBuild['page_count'] > 0,
                'page_count' => (int) $pageBuild['page_count'],
                'representation' => 'server_generated_html',
                'source_sha256' => $locked->source_sha256,
            ];
            $structure['analysis_representation'] = $sourceExtension === 'docx' ? 'converted_pdf' : ($inspection['detected_type'] ?? 'unknown');
            $structure['fact_count'] = $storedFactCount;
            $structure['ai_review'] = [
                'review_id' => $payload['review_id'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'findings' => $payload['findings'] ?? [],
                'warnings' => $payload['warnings'] ?? [],
                'provider_status' => $payload['provider_status'] ?? [],
                'analysis_batch_count' => (int) ($payload['analysis_batch_count'] ?? 0),
                'successful_analysis_batches' => (int) ($payload['successful_analysis_batches'] ?? 0),
                'analyzed_characters' => (int) ($payload['analyzed_characters'] ?? 0),
                'security_gate' => $payload['security_gate'] ?? null,
                'publication_authorized' => false,
            ];

            if ($file->is_primary && is_array($sample) && is_string($sample['bytes'] ?? null)) {
                $sampleBytes = $sample['bytes'];
                $samplePath = 'store/products/'.$locked->product_id.'/artifacts/'.$locked->source_sha256.'/sample-v1.pdf';
                $existingSample = $locked->artifacts()->where('artifact_type', 'sample_pdf')->where('version', 1)->first();
                $sampleDisk = Storage::disk($existingSample?->disk ?: 'private');
                $samplePathForReader = null;
                if ($existingSample
                    && data_get($existingSample->metadata_json, 'generator') === 'laravel_fpdi'
                    && $sampleDisk->exists($existingSample->path)) {
                    $samplePathForReader = $existingSample->path;
                    $structure['sample'] = [
                        'artifact_type' => 'sample_pdf',
                        'selected_pages' => (array) data_get($existingSample->metadata_json, 'selected_pages', []),
                        'source_page_count' => (int) data_get($existingSample->metadata_json, 'source_page_count', 0),
                        'included_page_count' => (int) data_get($existingSample->metadata_json, 'included_page_count', 0),
                        'sha256' => $existingSample->sha256,
                        'generated_once' => true,
                    ];
                } elseif (Storage::disk('private')->put($samplePath, $sampleBytes)) {
                    $artifact = $locked->artifacts()->updateOrCreate(
                        ['artifact_type' => 'sample_pdf', 'version' => 1],
                        [
                            'disk' => 'private',
                            'path' => $samplePath,
                            'mime' => 'application/pdf',
                            'size_bytes' => strlen($sampleBytes),
                            'sha256' => $sample['sha256'],
                            'metadata_json' => [
                                'selected_pages' => $sample['selected_pages'],
                                'source_page_count' => $sample['source_page_count'],
                                'included_page_count' => $sample['included_page_count'],
                                'source_sha256' => $locked->source_sha256,
                                'generated_once' => true,
                                'generator' => 'laravel_fpdi',
                            ],
                        ]
                    );
                    $samplePathForReader = $artifact->path;
                    $structure['sample'] = [
                        'artifact_type' => 'sample_pdf',
                        'selected_pages' => $sample['selected_pages'],
                        'source_page_count' => $sample['source_page_count'],
                        'included_page_count' => $sample['included_page_count'],
                        'sha256' => $artifact->sha256,
                        'generated_once' => true,
                        'generator' => 'laravel_fpdi',
                    ];
                }

                if ($samplePathForReader) {
                    StoreProductPreview::query()->updateOrCreate(
                        ['product_id' => $locked->product_id, 'path' => $samplePathForReader],
                        [
                            'disk' => 'private',
                            'mime' => 'application/pdf',
                            'page_number' => 1,
                            'source_page_count' => (int) $sample['source_page_count'],
                            'included_page_count' => (int) $sample['included_page_count'],
                            'kind' => 'pdf',
                            'access_level' => 'sample',
                            'watermarked' => true,
                            'is_active' => true,
                            'sort_order' => 0,
                        ]
                    );
                }
            }

            if ($file->is_primary && ($product->download_policy ?? 'signed') === 'reader_only') {
                $fullReaderPath = null;
                $fullReaderDisk = 'private';
                $sourceExtension = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION));
                if ($sourceExtension === 'pdf') {
                    $fullReaderPath = $file->path;
                    $fullReaderDisk = $file->disk ?: 'private';
                } elseif ($sourceExtension === 'docx') {
                    $readerPdf = $locked->artifacts()->where('artifact_type', 'reader_pdf')->where('version', 1)->first();
                    if ($readerPdf) {
                        $fullReaderPath = $readerPdf->path;
                        $fullReaderDisk = $readerPdf->disk ?: 'private';
                    }
                }

                if ($fullReaderPath && Storage::disk($fullReaderDisk)->exists($fullReaderPath)) {
                    StoreProductPreview::query()->updateOrCreate(
                        ['product_id' => $locked->product_id, 'path' => $fullReaderPath],
                        [
                            'disk' => $fullReaderDisk,
                            'mime' => 'application/pdf',
                            'page_number' => 1,
                            'kind' => 'pdf',
                            'access_level' => 'full',
                            'watermarked' => true,
                            'is_active' => true,
                            'sort_order' => 1,
                        ]
                    );
                }
            }

            $providers = (array) ($payload['provider_status'] ?? []);
            $hasChunks = $locked->chunks()->exists();
            $expectedBatches = (int) ($payload['analysis_batch_count'] ?? 0);
            $successfulBatches = (int) ($payload['successful_analysis_batches'] ?? 0);
            $analysisBatchesComplete = $expectedBatches > 0 && $successfulBatches >= $expectedBatches;
            $extractionComplete = ! (bool) ($inspection['extraction_truncated'] ?? false);
            $hasSemanticReview = ($providers['gemini'] ?? null) === 'completed' && $analysisBatchesComplete;
            $status = ! $hasChunks
                ? 'partial'
                : (! $extractionComplete ? 'partial' : ($hasSemanticReview ? 'completed' : 'extracted'));

            $structure['completeness'] = [
                'extraction_complete' => $extractionComplete,
                'analysis_batches_complete' => $analysisBatchesComplete,
                'analysis_batch_count' => $expectedBatches,
                'successful_analysis_batches' => $successfulBatches,
                'source_hash_verified' => true,
                'status' => $status,
            ];
            $structure['reader']['ready'] = $status === 'completed' && (int) ($pageBuild['page_count'] ?? 0) > 0;
            $structure['reader']['status'] = $status;

            $locked->update([
                'processing_status' => $status,
                'detected_type' => $sourceExtension === 'docx' ? 'docx' : ($inspection['detected_type'] ?? $locked->detected_type),
                'page_count' => isset($inspection['page_count']) ? (int) $inspection['page_count'] : $locked->page_count,
                'character_count' => $totalCharacters,
                'word_count' => $totalWords,
                'structure_json' => $structure,
                'failure_code' => null,
                'processed_at' => now(),
            ]);
        });

        $processedDocument = $document->fresh();
        if ($processedDocument && $processedDocument->processing_status === 'completed' && $processedDocument->chunks()->exists()) {
            GenerateProductDocumentAudio::dispatch($processedDocument->id);
        } elseif ($processedDocument && $processedDocument->chunks()->exists()) {
            $structure = (array) ($processedDocument->structure_json ?? []);
            $structure['audio'] = ['status' => 'awaiting_semantic_review'];
            $processedDocument->update(['structure_json' => $structure]);
        }
    }

    public function failed(Throwable $exception): void
    {
        StoreProductDocument::query()->whereKey($this->documentId)->update([
            'processing_status' => 'failed',
            'failure_code' => 'document_processing_failed',
            'updated_at' => now(),
        ]);
    }
}
