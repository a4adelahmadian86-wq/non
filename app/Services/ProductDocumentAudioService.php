<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductDocumentAudioService
{
    public function configured(): bool
    {
        return filled(config('services.document_audio.url')) && filled(config('services.document_audio.token'));
    }

    /**
     * Synthesize each source-located chunk once, then join the PCM frames into one private WAV.
     * Segment offsets retain the exact chunk/page locator for reader synchronization.
     */
    public function generate(array $chunks): ?array
    {
        if (! $this->configured() || $chunks === []) {
            return null;
        }

        $url = (string) config('services.document_audio.url');
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://127.0.0.1:')) {
            throw new RuntimeException('product_audio_requires_trusted_endpoint');
        }

        $token = (string) config('services.document_audio.token');
        $voiceId = (string) config('services.document_audio.voice_id', 'farast-document-fa-male-37-v1');
        if (count($chunks) > 300) {
            throw new RuntimeException('product_audio_chunk_limit_exceeded');
        }

        $pcm = '';
        $format = null;
        $segments = [];
        $durationMs = 0;
        $maxAudioBytes = 100 * 1024 * 1024;

        foreach ($chunks as $index => $chunk) {
            $text = trim((string) ($chunk['content_text'] ?? ''));
            if ($text === '') {
                continue;
            }

            foreach ($this->splitForSpeech($text) as $speechText) {
                $response = Http::accept('audio/wav')
                    ->withToken($token)
                    ->asJson()
                    ->connectTimeout(8)
                    ->timeout(90)
                    ->post($url, [
                        'text' => $speechText,
                        'language' => 'fa-IR',
                        'voice_id' => $voiceId,
                    ]);

                if (! $response->successful()) {
                    throw new RuntimeException('product_audio_provider_failed');
                }

                $parsed = $this->parseWav($response->body());
                $currentFormat = $parsed['format'];
                if ($format === null) {
                    $format = $currentFormat;
                } elseif ($format !== $currentFormat) {
                    throw new RuntimeException('product_audio_format_changed');
                }

                $segmentBytes = strlen($parsed['pcm']);
                if ($segmentBytes === 0 || strlen($pcm) + $segmentBytes > $maxAudioBytes) {
                    throw new RuntimeException('product_audio_size_limit');
                }

                $startMs = $durationMs;
                $segmentDuration = (int) round(($segmentBytes / $format['byte_rate']) * 1000);
                $durationMs += $segmentDuration;
                $pcm .= $parsed['pcm'];

                $segments[] = [
                    'chunk_index' => (int) ($chunk['chunk_index'] ?? $index),
                    'chunk_type' => (string) ($chunk['chunk_type'] ?? 'text'),
                    'locator' => is_array($chunk['locator'] ?? null) ? $chunk['locator'] : [],
                    'start_ms' => $startMs,
                    'end_ms' => $durationMs,
                    'text' => $speechText,
                    'text_sha256' => hash('sha256', $speechText),
                ];
            }
        }

        if ($pcm === '' || $format === null) {
            return null;
        }

        return [
            'audio' => $this->buildWav($pcm, $format),
            'mime' => 'audio/wav',
            'voice_id' => $voiceId,
            'duration_ms' => $durationMs,
            'segments' => $segments,
        ];
    }

    /**
     * Split at paragraph/sentence boundaries and never silently truncate source text.
     * The returned pieces are kept in reading order for line-level reader timing.
     */
    private function splitForSpeech(string $text): array
    {
        // Preserve sentence boundaries so one synthesis request maps to one exact,
        // reusable interval in the single final document WAV.
        $sentences = preg_split('/(?<=[.!؟۔؛])\s+|[\r\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $pieces = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') continue;
            if (mb_strlen($sentence, 'UTF-8') <= 6000) {
                $pieces[] = $sentence;
                continue;
            }
            $parts = preg_split('/(?<=[،,])\s*/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [$sentence];
            $current = '';
            foreach ($parts as $part) {
                if ($current !== '' && mb_strlen($current.' '.$part, 'UTF-8') > 6000) {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach (mb_str_split($part, 6000, 'UTF-8') as $subpart) {
                    if ($current !== '' && mb_strlen($current.' '.$subpart, 'UTF-8') > 6000) {
                        $pieces[] = $current;
                        $current = '';
                    }
                    $current = $current === '' ? $subpart : $current.' '.$subpart;
                }
            }
            if ($current !== '') $pieces[] = $current;
        }
        return $pieces;
    }

    private function parseWav(string $wav): array
    {
        if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new RuntimeException('product_audio_invalid_wav');
        }

        $offset = 12;
        $format = null;
        $pcm = null;
        $length = strlen($wav);
        while ($offset + 8 <= $length) {
            $chunkId = substr($wav, $offset, 4);
            $chunkSize = unpack('Vsize', substr($wav, $offset + 4, 4))['size'] ?? 0;
            $start = $offset + 8;
            if ($chunkSize < 0 || $start + $chunkSize > $length) {
                throw new RuntimeException('product_audio_wav_chunk_invalid');
            }
            if ($chunkId === 'fmt ' && $chunkSize >= 16) {
                $fmt = unpack('vaudio_format/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits_per_sample', substr($wav, $start, 16));
                $format = [
                    'audio_format' => (int) $fmt['audio_format'],
                    'channels' => (int) $fmt['channels'],
                    'sample_rate' => (int) $fmt['sample_rate'],
                    'byte_rate' => (int) $fmt['byte_rate'],
                    'block_align' => (int) $fmt['block_align'],
                    'bits_per_sample' => (int) $fmt['bits_per_sample'],
                ];
            } elseif ($chunkId === 'data') {
                $pcm = substr($wav, $start, $chunkSize);
            }
            $offset = $start + $chunkSize + ($chunkSize % 2);
        }

        if (! $format || ! is_string($pcm) || $pcm === ''
            || $format['audio_format'] !== 1
            || $format['channels'] < 1 || $format['channels'] > 2
            || $format['sample_rate'] < 8000 || $format['sample_rate'] > 48000
            || $format['bits_per_sample'] !== 16
            || $format['byte_rate'] <= 0
            || $format['block_align'] <= 0) {
            throw new RuntimeException('product_audio_wav_format_unsupported');
        }

        return ['format' => $format, 'pcm' => $pcm];
    }

    private function buildWav(string $pcm, array $format): string
    {
        $dataLength = strlen($pcm);
        if ($dataLength > 0xFFFFFFFF - 36) {
            throw new RuntimeException('product_audio_wav_too_large');
        }
        $fmt = pack(
            'vvVVvv',
            $format['audio_format'],
            $format['channels'],
            $format['sample_rate'],
            $format['byte_rate'],
            $format['block_align'],
            $format['bits_per_sample']
        );

        return 'RIFF'.pack('V', 36 + $dataLength).'WAVEfmt '.pack('V', 16).$fmt.'data'.pack('V', $dataLength).$pcm;
    }
}
