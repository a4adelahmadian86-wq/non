<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
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
    public int $timeout = 240;

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
            $payload = $supervisor->reviewFile($stream, $file->original_name, [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'primary_scan_status' => 'clean',
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
            fclose($stream);
        }

        $chunks = is_array($payload['content_chunks'] ?? null) ? array_slice($payload['content_chunks'], 0, 120) : [];
        $totalCharacters = 0;
        $totalWords = 0;

        DB::transaction(function () use ($document, $payload, $chunks, &$totalCharacters, &$totalWords) {
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
            $structure['ai_review'] = [
                'review_id' => $payload['review_id'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'findings' => $payload['findings'] ?? [],
                'warnings' => $payload['warnings'] ?? [],
                'provider_status' => $payload['provider_status'] ?? [],
                'security_gate' => $payload['security_gate'] ?? null,
                'publication_authorized' => false,
            ];

            $providers = (array) ($payload['provider_status'] ?? []);
            $hasChunks = $locked->chunks()->exists();
            $hasSemanticReview = ($providers['gemini'] ?? null) === 'completed';
            $status = $hasChunks
                ? ($hasSemanticReview ? 'completed' : 'extracted')
                : 'partial';

            $locked->update([
                'processing_status' => $status,
                'detected_type' => $inspection['detected_type'] ?? $locked->detected_type,
                'page_count' => isset($inspection['page_count']) ? (int) $inspection['page_count'] : $locked->page_count,
                'character_count' => $totalCharacters,
                'word_count' => $totalWords,
                'structure_json' => $structure,
                'failure_code' => null,
                'processed_at' => now(),
            ]);
        });
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
