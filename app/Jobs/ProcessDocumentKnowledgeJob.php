<?php

namespace App\\Jobs;

use App\\Models\\StoreDocumentRecord;
use App\\Services\\ProductSupervisorClient;
use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\Storage;
use Throwable;

class ProcessDocumentKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('document-analysis');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProductSupervisorClient $supervisor): void
    {
        $document = StoreDocumentRecord::query()
            ->with(['file', 'product.category', 'product.tags'])
            ->findOrFail($this->documentId);

        $file = $document->file;
        if (! $file || ! $file->is_active || ! $file->sha256 || ! $supervisor->configured()) {
            $document->update(['status' => 'waiting_provider', 'last_error_code' => 'document_source_or_provider_unavailable']);
            return;
        }

        $disk = Storage::disk($file->disk ?: 'private');
        if (! $disk->exists($file->path) || (int) $disk->size($file->path) > 25 * 1024 * 1024) {
            $document->update(['status' => 'needs_review', 'last_error_code' => 'document_source_unavailable_or_over_ai_limit']);
            return;
        }

        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            $document->update(['status' => 'retrying', 'last_error_code' => 'document_stream_unavailable']);
            throw new \\RuntimeException('document_stream_unavailable');
        }

        $document->update(['status' => 'processing', 'last_error_code' => null]);
        try {
            $hashContext = hash_init('sha256');
            hash_update_stream($hashContext, $stream);
            $actualHash = hash_final($hashContext);
            rewind($stream);
            if (! hash_equals(strtolower((string) $file->sha256), strtolower($actualHash))) {
                $document->update(['status' => 'blocked', 'last_error_code' => 'document_checksum_mismatch']);
                return;
            }

            $product = $document->product;
            $response = $supervisor->reviewFile($stream, $file->original_name ?: basename($file->path), [
                'product_id' => (string) $product->id,
                'product_title' => (string) $product->title,
                'product_type' => (string) $product->type,
                'primary_scan_status' => 'clean',
                'category_name' => $product->category?->name,
                'tags_json' => json_encode($product->tags->pluck('name')->take(30)->values()->all(), JSON_UNESCAPED_UNICODE),
                'description' => mb_substr((string) $product->description, 0, 8000),
            ]);
        } catch (Throwable $exception) {
            $document->update(['status' => 'retrying', 'last_error_code' => class_basename($exception)]);
            throw $exception;
        } finally {
            fclose($stream);
        }

        $inspection = (array) ($response['inspection'] ?? []);
        $chunks = is_array($response['content_chunks'] ?? null) ? array_slice($response['content_chunks'], 0, 150) : [];
        $facts = is_array($response['facts'] ?? null) ? array_slice($response['facts'], 0, 100) : [];
        $summary = mb_substr((string) ($response['summary'] ?? ''), 0, 700);
        $providerStatus = (array) ($response['provider_status'] ?? []);
        $findings = is_array($response['findings'] ?? null) ? array_slice($response['findings'], 0, 12) : [];
        $warnings = is_array($response['warnings'] ?? null) ? array_slice($response['warnings'], 0, 20) : [];

        $document->chunks()->delete();
        $document->facts()->delete();

        $totalCharacters = 0;
        $wordCount = 0;
        foreach ($chunks as $index => $chunk) {
            if (! is_array($chunk)) continue;
            $text = trim((string) ($chunk['text'] ?? ''));
            if ($text === '') continue;
            $text = mb_substr($text, 0, 5000);
            $totalCharacters += mb_strlen($text);
            $wordCount += str_word_count($text);
            $document->chunks()->create([
                'chunk_index' => $index,
                'content_type' => preg_replace('/[^a-z0-9_]/i', '', (string) ($chunk['content_type'] ?? 'text')) ?: 'text',
                'page_number' => isset($chunk['page_number']) ? max(1, (int) $chunk['page_number']) : null,
                'heading' => mb_substr((string) ($chunk['heading'] ?? ''), 0, 500) ?: null,
                'content' => $text,
                'structured_content' => is_array($chunk['structured_content'] ?? null) ? $chunk['structured_content'] : null,
                'source_anchor' => is_array($chunk['source_anchor'] ?? null) ? $chunk['source_anchor'] : [],
                'content_hash' => hash('sha256', $text),
            ]);
        }

        foreach ($facts as $fact) {
            if (! is_array($fact) || trim((string) ($fact['key'] ?? '')) === '' || trim((string) ($fact['value'] ?? '')) === '') continue;
            $confidence = is_numeric($fact['confidence'] ?? null) ? max(0, min(1, (float) $fact['confidence'])) : 0;
            $document->facts()->create([
                'fact_key' => mb_substr((string) $fact['key'], 0, 180),
                'fact_value' => mb_substr((string) $fact['value'], 0, 2000),
                'confidence' => $confidence,
                'evidence' => is_array($fact['evidence'] ?? null) ? $fact['evidence'] : [],
                'model' => (string) ($response['model'] ?? config('services.product_supervisor.model', 'gemini')),
                'knowledge_version' => $document->knowledge_version + 1,
            ]);
        }

        $geminiStatus = (string) ($providerStatus['gemini'] ?? 'not_requested');
        $document->update([
            'detected_mime' => $file->mime,
            'detected_type' => (string) ($inspection['detected_type'] ?? $document->detected_type ?? 'unknown'),
            'page_count' => isset($inspection['page_count']) ? max(0, (int) $inspection['page_count']) : $document->page_count,
            'word_count' => $wordCount,
            'extracted_characters' => $totalCharacters,
            'status' => $geminiStatus === 'completed' && $chunks !== [] ? 'ready' : ($chunks !== [] ? 'extracted' : 'needs_review'),
            'knowledge_version' => $document->knowledge_version + 1,
            'metadata' => [
                'summary' => $summary,
                'findings' => $findings,
                'warnings' => $warnings,
                'provider_status' => $providerStatus,
                'security_gate' => $response['security_gate'] ?? 'manual_scan_required',
                'redacted_sensitive_items' => (int) ($response['redacted_sensitive_items'] ?? 0),
                'inspection' => $inspection,
            ],
            'last_error_code' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        StoreDocumentRecord::query()->whereKey($this->documentId)->update([
            'status' => 'failed',
            'last_error_code' => class_basename($exception),
            'updated_at' => now(),
        ]);
    }
}
