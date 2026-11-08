<?php

namespace App\Jobs;

use App\Models\StoreProductDocument;
use App\Models\StoreProductDocumentAudio;
use App\Models\StoreProductDocumentAudioSegment;
use App\Models\StoreProductUploadEvent;
use App\Services\PcmWavConcatenator;
use App\Services\ProductDocumentTtsClient;
use App\Services\ProductFileStorageSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateProductDocumentAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(public readonly int $documentId)
    {
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function handle(ProductDocumentTtsClient $tts, PcmWavConcatenator $wav, ProductFileStorageSelector $storageSelector): void
    {
        $document = StoreProductDocument::query()->with(['blocks', 'product'])->findOrFail($this->documentId);
        $existing = StoreProductDocumentAudio::query()->where('document_id', $document->id)->first();
        if ($existing && $existing->state === 'ready' && (int) $existing->content_version === (int) $document->content_version) {
            return;
        }

        $url = trim((string) config('services.product_document_tts.url'));
        $token = trim((string) config('services.product_document_tts.token'));
        if ($url === '' || $token === '') {
            StoreProductDocumentAudio::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'storage_disk' => 'private',
                    'storage_path' => 'store/product-audio/pending/'.$document->id.'.wav',
                    'mime' => 'audio/wav',
                    'state' => 'disabled',
                    'voice_profile' => null,
                    'content_version' => $document->content_version,
                    'alignment_metadata' => ['reason' => 'local_document_tts_not_configured'],
                ]
            );
            $this->recordEvent($document, 'pending', 'نسخه صوتی در انتظار تنظیم موتور محلی است', 'هیچ سرویس صوتی ثالثی فراخوانی نشد.');
            return;
        }

        $textBlocks = $document->blocks
            ->filter(fn ($block) => trim((string) $block->text_content) !== '')
            ->values();
        if ($textBlocks->isEmpty()) {
            StoreProductDocumentAudio::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'storage_disk' => 'private',
                    'storage_path' => 'store/product-audio/unavailable/'.$document->id.'.wav',
                    'mime' => 'audio/wav',
                    'state' => 'unavailable',
                    'content_version' => $document->content_version,
                    'alignment_metadata' => ['reason' => 'no_text_blocks'],
                ]
            );
            $this->recordEvent($document, 'partial', 'برای این فایل متن قابل خواندن جهت صوت‌سازی پیدا نشد', 'پس از تکمیل OCR یا استخراج ساختاری می‌توان دوباره تلاش کرد.');
            return;
        }

        $this->recordEvent($document, 'running', 'تولید نسخه صوتی هماهنگ آغاز شد', 'صوت هر بخش از متن ذخیره‌شده تولید و به همان بلوک متصل می‌شود.');
        $pcmPath = tempnam(sys_get_temp_dir(), 'farast-pcm-');
        $wavPath = tempnam(sys_get_temp_dir(), 'farast-wav-');
        if (! is_string($pcmPath) || ! is_string($wavPath)) {
            if (is_string($pcmPath)) @unlink($pcmPath);
            if (is_string($wavPath)) @unlink($wavPath);
            throw new RuntimeException('document_tts_temp_storage_unavailable');
        }

        $pcmHandle = fopen($pcmPath, 'wb');
        if (! is_resource($pcmHandle)) {
            @unlink($pcmPath);
            @unlink($wavPath);
            throw new RuntimeException('document_tts_temp_stream_unavailable');
        }

        $format = null;
        $cursor = 0;
        $segmentRows = [];
        $chunkCount = 0;
        try {
            foreach ($textBlocks as $block) {
                foreach ($this->splitText((string) $block->text_content) as $chunk) {
                    if (++$chunkCount > 500) {
                        throw new RuntimeException('document_tts_segment_limit');
                    }
                    $audio = $tts->synthesize($chunk);
                    if ($format === null) {
                        $format = $audio['format'];
                    } elseif ($format !== $audio['format']) {
                        throw new RuntimeException('document_tts_format_mismatch');
                    }

                    $pcm = $audio['pcm'];
                    $written = fwrite($pcmHandle, $pcm);
                    if ($written !== strlen($pcm)) {
                        throw new RuntimeException('document_tts_pcm_write_failed');
                    }
                    $duration = (int) round((strlen($pcm) / $format['byte_rate']) * 1000);
                    $segmentRows[] = [
                        'document_block_id' => $block->id,
                        'sequence' => count($segmentRows) + 1,
                        'start_ms' => $cursor,
                        'end_ms' => $cursor + $duration,
                        'visual_locator' => [
                            'block_type' => $block->block_type,
                            'page_number' => $block->page_number,
                            'section_path' => $block->section_path,
                        ],
                    ];
                    $cursor += $duration;
                    unset($audio, $pcm);
                }
            }

            fflush($pcmHandle);
            $pcmBytes = ftell($pcmHandle);
            if (! is_int($pcmBytes) || $pcmBytes < 1 || ! $format) {
                throw new RuntimeException('document_tts_empty_output');
            }
            if ($pcmBytes > 400 * 1024 * 1024) {
                throw new RuntimeException('document_tts_output_size_limit');
            }

            $wavHandle = fopen($wavPath, 'wb');
            if (! is_resource($wavHandle)) {
                throw new RuntimeException('document_tts_output_stream_unavailable');
            }
            fwrite($wavHandle, $wav->header($format, $pcmBytes));
            rewind($pcmHandle);
            stream_copy_to_stream($pcmHandle, $wavHandle);
            fflush($wavHandle);
            fclose($wavHandle);
            fclose($pcmHandle);
            $pcmHandle = null;

            $diskName = $storageSelector->select($document->product, ['kind' => 'audio']);
            $path = 'store/product-audio/'.$document->product_id.'/'.$document->id.'-v'.$document->content_version.'.wav';
            $targetDisk = Storage::disk($diskName);
            $stream = fopen($wavPath, 'rb');
            if (! is_resource($stream)) {
                throw new RuntimeException('document_tts_output_stream_unavailable');
            }
            try {
                if (! $targetDisk->writeStream($path, $stream)) {
                    throw new RuntimeException('document_tts_storage_failed');
                }
            } finally {
                fclose($stream);
            }

            try {
                DB::transaction(function () use ($document, $diskName, $path, $cursor, $segmentRows, $chunkCount) {
                    $audio = StoreProductDocumentAudio::updateOrCreate(
                        ['document_id' => $document->id],
                        [
                            'storage_disk' => $diskName,
                            'storage_path' => $path,
                            'mime' => 'audio/wav',
                            'duration_ms' => $cursor,
                            'state' => 'ready',
                            'voice_profile' => (string) config('services.product_document_tts.voice_id', 'farast-fa-male-v1'),
                            'content_version' => $document->content_version,
                            'alignment_metadata' => [
                                'alignment_version' => 1,
                                'content_version' => $document->content_version,
                                'segment_count' => count($segmentRows),
                                'chunk_count' => $chunkCount,
                                'generated_once' => true,
                            ],
                            'generated_at' => now(),
                        ]
                    );
                    $audio->segments()->delete();
                    foreach ($segmentRows as $segment) {
                        StoreProductDocumentAudioSegment::create(array_merge($segment, ['audio_id' => $audio->id]));
                    }

                    $manifest = (array) $document->content_manifest;
                    $manifest['audio_status'] = 'ready';
                    $manifest['audio_content_version'] = $document->content_version;
                    $document->update(['content_manifest' => $manifest]);
                });
            } catch (Throwable $exception) {
                $targetDisk->delete($path);
                throw $exception;
            }

            $this->recordEvent($document, 'completed', 'نسخه صوتی یک‌باره آماده شد', 'نقشه زمانی صوت به بلوک‌های متن و شماره صفحه متصل شد.', [
                'duration_ms' => $cursor,
                'segments' => count($segmentRows),
            ]);
        } finally {
            if (is_resource($pcmHandle)) fclose($pcmHandle);
            @unlink($pcmPath);
            @unlink($wavPath);
        }
    }

    public function failed(Throwable $exception): void
    {
        $audio = StoreProductDocumentAudio::query()->where('document_id', $this->documentId)->first();
        if ($audio) $audio->update(['state' => 'failed']);
        $document = StoreProductDocument::query()->find($this->documentId);
        if ($document) $this->recordEvent($document, 'failed', 'تولید نسخه صوتی ناموفق بود', 'امکان تلاش مجدد وجود دارد.');
        Log::warning('Product document TTS failed', [
            'document_id' => $this->documentId,
            'exception' => class_basename($exception),
        ]);
    }

    private function splitText(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $chunks = [];
        while ($text !== '') {
            if (mb_strlen($text) <= 5500) {
                $chunks[] = $text;
                break;
            }
            $part = mb_substr($text, 0, 5500);
            $space = mb_strrpos($part, ' ');
            if ($space !== false && $space > 3500) $part = mb_substr($part, 0, $space);
            $chunks[] = trim($part);
            $text = ltrim(mb_substr($text, mb_strlen($part)));
        }
        return $chunks;
    }

    private function recordEvent(StoreProductDocument $document, string $state, string $title, string $detail, array $metadata = []): void
    {
        if (! $document->upload_session_id) return;
        StoreProductUploadEvent::create([
            'upload_session_id' => $document->upload_session_id,
            'stage' => 'audio_generation',
            'state' => $state,
            'title' => $title,
            'detail' => $detail,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
