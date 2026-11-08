<?php

namespace App\\Jobs;

use App\\Models\\StoreDocumentAudioTrack;
use App\\Models\\StoreDocumentRecord;
use App\\Services\\PiperDocumentAudioClient;
use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\Storage;
use Throwable;

class GenerateDocumentAudioTrackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue('document-audio');
    }

    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    public function handle(PiperDocumentAudioClient $tts): void
    {
        $document = StoreDocumentRecord::query()->with(['file', 'chunks'])->findOrFail($this->documentId);
        $file = $document->file;
        if (! $file || ! $file->is_active || ! hash_equals(strtolower((string) $document->source_sha256), strtolower((string) $file->sha256))) {
            return;
        }

        $existing = StoreDocumentAudioTrack::query()
            ->where('document_id', $document->id)
            ->where('source_sha256', $document->source_sha256)
            ->where('status', 'ready')
            ->first();
        if ($existing && Storage::disk($existing->disk)->exists($existing->path)) {
            return;
        }

        $segments = $document->chunks
            ->sortBy('chunk_index')
            ->map(fn ($chunk) => [
                'text' => trim((string) $chunk->content),
                'source_anchor' => [
                    'chunk_id' => $chunk->id,
                    'chunk_index' => $chunk->chunk_index,
                    'content_type' => $chunk->content_type,
                    'page_number' => $chunk->page_number,
                    'source_anchor' => $chunk->source_anchor ?? [],
                ],
            ])
            ->filter(fn ($item) => $item['text'] !== '')
            ->take(100)
            ->values()
            ->all();

        if ($segments === []) {
            $document->update(['metadata' => array_merge((array) $document->metadata, ['audio_status' => 'no_readable_text'])]);
            return;
        }

        try {
            $result = $tts->synthesize($segments);
            $path = 'store/document-audio/'.$document->product_id.'/'.$file->id.'-'.$document->source_sha256.'.wav';
            if (! Storage::disk('private')->put($path, $result['audio'])) {
                throw new \\RuntimeException('document_audio_storage_failed');
            }

            $track = StoreDocumentAudioTrack::query()->updateOrCreate(
                ['document_id' => $document->id, 'source_sha256' => $document->source_sha256],
                [
                    'disk' => 'private',
                    'path' => $path,
                    'mime' => 'audio/wav',
                    'duration_ms' => (int) (end($result['timings'])['end_ms'] ?? 0),
                    'status' => 'ready',
                    'provider' => $result['provider'],
                    'generated_at' => now(),
                ]
            );
            $track->segments()->delete();
            foreach ($result['timings'] as $index => $timing) {
                $source = $segments[$index] ?? [];
                $track->segments()->create([
                    'segment_index' => $index,
                    'start_ms' => max(0, (int) ($timing['start_ms'] ?? 0)),
                    'end_ms' => max(0, (int) ($timing['end_ms'] ?? 0)),
                    'text' => mb_substr((string) ($source['text'] ?? ''), 0, 5000),
                    'source_anchor' => $source['source_anchor'] ?? [],
                ]);
            }

            $document->update(['metadata' => array_merge((array) $document->metadata, [
                'audio_status' => 'ready',
                'audio_generated_at' => now()->toISOString(),
                'audio_track_id' => $track->id,
            ])]);
        } catch (Throwable $exception) {
            $document->update(['metadata' => array_merge((array) $document->metadata, [
                'audio_status' => 'retrying',
                'audio_error' => class_basename($exception),
            ])]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $document = StoreDocumentRecord::query()->find($this->documentId);
        if ($document) {
            $document->update(['metadata' => array_merge((array) $document->metadata, [
                'audio_status' => 'failed',
                'audio_error' => class_basename($exception),
            ])]);
        }
    }
}
