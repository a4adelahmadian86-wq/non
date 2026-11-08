<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductDocumentAudioService
{
    public function configured(): bool
    {
        return filled(config('services.voice_tts.url')) && filled(config('services.voice_tts.token'));
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

        $url = (string) config('services.voice_tts.url');
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://127.0.0.1:')) {
            throw new RuntimeException('product_audio_requires_trusted_endpoint');
        }

        $token = (string) config('services.voice_tts.token');
        $voiceId = (string) config('services.voice_tts.voice_id', 'farast-fa-male-v1');
        $pcm = '';
        $format = null;
        $segments = [];
        $durationMs = 0;
        $maxAudioBytes = 100 * 1024 * 1024;

        foreach (array_slice($chunks, 0, 120) as $index => $chunk) {
            $text = trim((string) ($chunk['content_text'] ?? ''));
            if ($text === '') continue;

            $response = Http::accept('audio/wav')
                ->withToken($token)
                ->asJson()
                ->connectTimeout(8)
                ->timeout(90)
                ->post($url, [
                    'text' => mb_substr($text, 0, 6000),
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
                'text_sha256' => hash('sha256', $text),
            ];
        }

        if ($pcm === '' || $format === null) {
            return null;
        }

        $wav = $this->buildWav($pcm, $format);
        return [
            'audio' => $wav,
            'mime' => 'audio/wav',
            'voice_id' => $voiceId,
            'duration_ms' => $durationMs,
            'segments' => $segments,
        ];
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
