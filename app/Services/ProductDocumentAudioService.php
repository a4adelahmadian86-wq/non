<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
                    ->connectTimeout(75) // Free Render instances can need about a minute to wake from idle.
                    ->timeout(180)
                    ->retry(3, 3000, static fn ($exception) =>
                        $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && in_array($exception->response->status(), [502, 503, 504], true))
                    )
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

                $words = preg_split('/\s+/u', trim($speechText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $providerWords = (array) ($parsed['word_timings'] ?? []);
                $alignmentValid = count($providerWords) === count($words);
                $previousRelativeEnd = 0;
                if ($alignmentValid) {
                    foreach ($words as $wordIndex => $word) {
                        $candidate = (array) ($providerWords[$wordIndex] ?? []);
                        $candidateText = (string) ($candidate['text'] ?? '');
                        $relativeStart = is_numeric($candidate['start_ms'] ?? null) ? (int) $candidate['start_ms'] : -1;
                        $relativeEnd = is_numeric($candidate['end_ms'] ?? null) ? (int) $candidate['end_ms'] : -1;
                        if (! hash_equals($word, $candidateText)
                            || $relativeStart < $previousRelativeEnd
                            || $relativeEnd <= $relativeStart
                            || $relativeEnd > $segmentDuration + 1) {
                            $alignmentValid = false;
                            break;
                        }
                        $previousRelativeEnd = $relativeEnd;
                    }
                }

                $wordTimings = [];
                $alignmentQuality = $alignmentValid ? 'phoneme' : 'estimated';
                if ($alignmentValid) {
                    foreach ($words as $wordIndex => $word) {
                        $candidate = (array) $providerWords[$wordIndex];
                        $wordTimings[] = [
                            'text' => $word,
                            'start_ms' => $startMs + max(0, (int) $candidate['start_ms']),
                            'end_ms' => min($durationMs, $startMs + (int) $candidate['end_ms']),
                        ];
                    }
                } else {
                    $weights = array_map(static fn (string $word) => max(1, mb_strlen($word, 'UTF-8')), $words);
                    $totalWeight = max(1, array_sum($weights));
                    $wordCursor = $startMs;
                    foreach ($words as $wordIndex => $word) {
                        $wordEnd = $wordIndex === array_key_last($words)
                            ? $durationMs
                            : $wordCursor + (int) round($segmentDuration * ($weights[$wordIndex] / $totalWeight));
                        $wordTimings[] = [
                            'text' => $word,
                            'start_ms' => $wordCursor,
                            'end_ms' => max($wordCursor + 1, $wordEnd),
                        ];
                        $wordCursor = $wordEnd;
                    }
                }

                $segments[] = [
                    'chunk_index' => (int) ($chunk['chunk_index'] ?? $index),
                    'chunk_type' => (string) ($chunk['chunk_type'] ?? 'text'),
                    'locator' => is_array($chunk['locator'] ?? null) ? $chunk['locator'] : [],
                    'start_ms' => $startMs,
                    'end_ms' => $durationMs,
                    'text' => $speechText,
                    'words' => $wordTimings,
                    'alignment_quality' => $alignmentQuality,
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
        $wordTimings = [];
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
            } elseif ($chunkId === 'FARA' && $chunkSize <= 1000000) {
                $metadata = json_decode(substr($wav, $start, $chunkSize), true);
                if (is_array($metadata)
                    && (int) ($metadata['version'] ?? 0) === 1
                    && ($metadata['alignment'] ?? null) === 'phoneme'
                    && is_array($metadata['words'] ?? null)) {
                    $wordTimings = array_slice($metadata['words'], 0, 1500);
                }
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

        return ['format' => $format, 'pcm' => $pcm, 'word_timings' => $wordTimings];
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
