<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductPreview;
use App\Jobs\GenerateProductDocumentAudio;
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

class ProcessProductDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('documents');
    }

    public function handle(ProductSupervisorClient $supervisor): void
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
                $reviewStream = fopen('php://temp', 'w+b');
                if (! is_resource($reviewStream)) {
                    throw new \RuntimeException('product_docx_review_stream_failed');
                }
                fwrite($reviewStream, $pdfBytes);
                rewind($reviewStream);
                $reviewFilename = pathinfo($file->original_name, PATHINFO_FILENAME).'.pdf';
            } else {
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

        $chunks = is_array($payload['content_chunks'] ?? null) ? array_slice($payload['content_chunks'], 0, 120) : [];
        $totalCharacters = 0;
        $totalWords = 0;

        DB::transaction(function () use ($document, $file, $product, $sourceExtension, $payload, $chunks, &$totalCharacters, &$totalWords) {
            $locked = StoreProductDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->chunks()->delete();

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

            $inspection = is_array($payload['inspection'] ?? null) ? $payload['inspection'] : [];
            $structure = (array) ($locked->structure_json ?? []);
            $structure['inspection'] = $inspection;
            $structure['analysis_representation'] = $sourceExtension === 'docx' ? 'converted_pdf' : ($inspection['detected_type'] ?? 'unknown');
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

            $sampleBase64 = $payload['sample_pdf_base64'] ?? null;
            if ($file->is_primary && is_string($sampleBase64) && $sampleBase64 !== '') {
                $sampleBytes = base64_decode($sampleBase64, true);
                if (is_string($sampleBytes) && strlen($sampleBytes) <= 8 * 1024 * 1024 && str_starts_with($sampleBytes, '%PDF-')) {
                    $sampleHash = hash('sha256', $sampleBytes);
                    $samplePath = 'store/products/'.$locked->product_id.'/artifacts/'.$locked->source_sha256.'/sample-v1.pdf';
                    $existingSample = $locked->artifacts()->where('artifact_type', 'sample_pdf')->where('version', 1)->first();
                    $sampleDisk = Storage::disk($existingSample?->disk ?: 'private');
                    $samplePathForReader = null;
                    if ($existingSample && $sampleDisk->exists($existingSample->path)) {
                        $samplePathForReader = $existingSample->path;
                        $structure['sample'] = [
                            'artifact_type' => 'sample_pdf',
                            'selected_pages' => (array) data_get($existingSample->metadata_json, 'selected_pages', []),
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
                                'sha256' => $sampleHash,
                                'metadata_json' => [
                                    'selected_pages' => array_values(array_filter($payload['sample_page_numbers'] ?? [], 'is_int')),
                                    'source_sha256' => $locked->source_sha256,
                                    'generated_once' => true,
                                ],
                            ]
                        );
                        $samplePathForReader = $artifact->path;
                        $structure['sample'] = [
                            'artifact_type' => 'sample_pdf',
                            'selected_pages' => array_values(array_filter($payload['sample_page_numbers'] ?? [], 'is_int')),
                            'sha256' => $artifact->sha256,
                            'generated_once' => true,
                        ];
                    }

                    if ($samplePathForReader) {
                        StoreProductPreview::query()->updateOrCreate(
                            ['product_id' => $locked->product_id, 'path' => $samplePathForReader],
                            [
                                'disk' => 'private',
                                'mime' => 'application/pdf',
                                'page_number' => 1,
                                'kind' => 'pdf',
                                'access_level' => 'sample',
                                'watermarked' => true,
                                'is_active' => true,
                                'sort_order' => 0,
                            ]
                        );
                    }
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
