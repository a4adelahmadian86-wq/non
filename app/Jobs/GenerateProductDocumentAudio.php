<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentArtifact;
use App\Models\StoreProductPreview;
use App\Services\ProductDocumentAudioService;
use App\Services\Storage\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateProductDocumentAudio implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return 'product-document-audio:'.$this->documentId;
    }

    public function __construct(public readonly int $documentId)
    {
        // Reuse the existing shared-hosting database worker; no extra queue consumer is required.
        $this->onQueue('default');
    }

    public function handle(ProductDocumentAudioService $audioService, StorageManager $storage): void
    {
        $document = StoreProductDocument::query()->with(['chunks', 'file'])->find($this->documentId);
        if (! $document || $document->chunks->isEmpty()) {
            return;
        }

        $existing = $document->artifacts()->where('artifact_type', 'audio_full_wav')->where('version', 3)->first();
        if ($existing && $storage->exists($existing->disk ?: 'private', $existing->path)) {
            $this->setAudioState($document, [
                'status' => 'completed',
                'artifact_type' => 'audio_full_wav',
                'engine' => 'farast-document-audio',
                'sha256' => $existing->sha256,
                'duration_ms' => (int) data_get($existing->metadata_json, 'duration_ms', 0),
                'segment_count' => count(data_get($existing->metadata_json, 'segments', [])),
                'generated_once' => true,
            ]);
            return;
        }

        if (! $audioService->configured()) {
            $this->setAudioState($document, ['status' => 'awaiting_provider', 'reason' => 'document_audio_not_configured']);
            return;
        }

        try {
            $chunks = $document->chunks->map(fn ($chunk) => [
                'chunk_index' => $chunk->chunk_index,
                'chunk_type' => $chunk->chunk_type,
                'locator' => $chunk->locator_json ?? [],
                'content_text' => $chunk->content_text,
            ])->all();

            $generated = $audioService->generate($chunks);
            if (! $generated || empty($generated['audio'])) {
                $this->setAudioState($document, ['status' => 'partial', 'reason' => 'no_audio_segments']);
                return;
            }

            $bytes = $generated['audio'];
            $sha256 = hash('sha256', $bytes);
            $managed = $storage->storeContents(
                $bytes,
                'audio-v3.wav',
                'audio/wav',
                StorageManager::CRITICAL,
                'store-product-artifact',
                StoreProductDocumentArtifact::class,
                null,
                (int) $document->product_id,
                'private'
            );

            $chunkByIndex = collect($chunks)->keyBy('chunk_index');
            $readerTimings = collect($generated['segments'])->map(function (array $segment) use ($chunkByIndex) {
                $chunk = $chunkByIndex->get((int) ($segment['chunk_index'] ?? -1));
                $locator = (array) ($segment['locator'] ?? []);
                $page = (int) ($locator['reader_page'] ?? $locator['page'] ?? $locator['page_start'] ?? 0);
                $text = trim((string) ($segment['text'] ?? $chunk['content_text'] ?? ''));
                if ($page < 1 || $text === '') return null;

                return [
                    'text' => $text,
                    'words' => array_map(static fn (array $word) => [
                        'text' => (string) ($word['text'] ?? ''),
                        'start' => round(((int) ($word['start_ms'] ?? 0)) / 1000, 3),
                        'end' => round(((int) ($word['end_ms'] ?? 0)) / 1000, 3),
                    ], (array) ($segment['words'] ?? [])),
                    'alignment_quality' => (string) ($segment['alignment_quality'] ?? 'estimated'),
                    'start' => round(((int) ($segment['start_ms'] ?? 0)) / 1000, 3),
                    'end' => round(((int) ($segment['end_ms'] ?? 0)) / 1000, 3),
                    'page' => $page,
                ];
            })->filter()->values()->all();

            try {
                [$artifact, $oldDisk, $oldPath] = DB::transaction(function () use ($document, $managed, $storage, $generated, $bytes, $sha256, $readerTimings): array {
                    $lockedDocument = StoreProductDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                    $oldArtifact = $lockedDocument->artifacts()
                        ->where('artifact_type', 'audio_full_wav')
                        ->where('version', 3)
                        ->lockForUpdate()
                        ->first();
                    $oldDisk = $oldArtifact?->disk ?: 'private';
                    $oldPath = $oldArtifact?->path;

                    $artifact = $lockedDocument->artifacts()->updateOrCreate(
                        ['artifact_type' => 'audio_full_wav', 'version' => 3],
                        [
                            'disk' => $managed['disk'],
                            'path' => $managed['path'],
                            'mime' => 'audio/wav',
                            'size_bytes' => strlen($bytes),
                            'sha256' => $sha256,
                            'metadata_json' => [
                                'voice_id' => $generated['voice_id'],
                                'duration_ms' => $generated['duration_ms'],
                                'segments' => $generated['segments'],
                                'source_sha256' => $lockedDocument->source_sha256,
                                'generated_once' => true,
                                'granularity' => 'phoneme_aligned_sentence_word',
                            ],
                        ]
                    );
                    $storage->bindReference((int) $managed['managed_file_id'], StoreProductDocumentArtifact::class, (int) $artifact->id, (int) $lockedDocument->product_id);

                    $fullReaderPreviews = StoreProductPreview::query()
                        ->where('product_id', $lockedDocument->product_id)
                        ->where('access_level', 'full')
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->get();
                    foreach ($fullReaderPreviews as $fullReaderPreview) {
                        $timingsForPreview = $fullReaderPreview->kind === 'reader_document'
                            ? array_values(array_filter(
                                $readerTimings,
                                static fn (array $timing) => (int) ($timing['page'] ?? 0) === (int) $fullReaderPreview->page_number
                            ))
                            : $readerTimings;

                        $fullReaderPreview->update([
                            'audio_disk' => $artifact->disk,
                            'audio_path' => $artifact->path,
                            'audio_mime' => 'audio/wav',
                            'audio_timing' => $timingsForPreview,
                        ]);
                    }

                    return [$artifact, $oldDisk, $oldPath];
                });
            } catch (Throwable $exception) {
                $storage->delete($managed['disk'], $managed['path']);
                throw $exception;
            }

            if ($oldPath && ($oldDisk !== $artifact->disk || $oldPath !== $artifact->path)) {
                $otherArtifactUsesOldPath = StoreProductDocumentArtifact::query()
                    ->where('path', $oldPath)
                    ->where(fn ($query) => $query->whereNull('disk')->orWhere('disk', $oldDisk))
                    ->where('id', '!=', $artifact->id)
                    ->exists();
                $previewStillUsesOldPath = StoreProductPreview::query()
                    ->where(fn ($query) => $query->where('path', $oldPath)->orWhere('audio_path', $oldPath))
                    ->get()
                    ->contains(fn (StoreProductPreview $preview) =>
                        ((string) $preview->path === $oldPath && ($preview->disk ?: 'private') === $oldDisk)
                        || ((string) $preview->audio_path === $oldPath && ($preview->audio_disk ?: 'private') === $oldDisk)
                    );
                if (! $otherArtifactUsesOldPath && ! $previewStillUsesOldPath) {
                    try { $storage->delete($oldDisk, $oldPath); } catch (Throwable $cleanupException) { report($cleanupException); }
                }
            }

            $this->setAudioState($document->fresh(), [
                'status' => 'completed',
                'artifact_type' => 'audio_full_wav',
                'sha256' => $artifact->sha256,
                'duration_ms' => (int) $generated['duration_ms'],
                'segment_count' => count($generated['segments']),
                'voice_id' => $generated['voice_id'],
                'engine' => 'farast-document-audio',
                'granularity' => 'phoneme_aligned_sentence_word',
                'generated_once' => true,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Product document audio generation failed', [
                'document_id' => $this->documentId,
                'exception' => class_basename($exception),
            ]);
            $this->setAudioState($document, ['status' => 'failed', 'reason' => 'audio_generation_failed']);
            throw $exception;
        }
    }

    private function setAudioState(StoreProductDocument $document, array $audioState): void
    {
        $document->refresh();
        $structure = (array) ($document->structure_json ?? []);
        $structure['audio'] = $audioState;
        $document->update(['structure_json' => $structure]);
    }

    public function failed(Throwable $exception): void
    {
        $document = StoreProductDocument::query()->find($this->documentId);
        if ($document) {
            $this->setAudioState($document, ['status' => 'failed', 'reason' => 'audio_job_failed']);
        }
    }
}
