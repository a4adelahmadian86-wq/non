<?php

namespace App\\Services;

use Illuminate\\Support\\Facades\\Http;
use RuntimeException;

class PiperDocumentAudioClient
{
    public function synthesize(array $segments): array
    {
        $url = trim((string) config('services.voice_tts.url', ''));
        $token = trim((string) config('services.voice_tts.token', ''));
        if ($url === '' || $token === '') {
            throw new RuntimeException('document_tts_not_configured');
        }

        $endpoint = preg_replace('#/v1/synthesize/?$#', '/v1/synthesize/segments', rtrim($url, '/'));
        if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://') && ! str_starts_with($endpoint, 'http://127.0.0.1')) {
            throw new RuntimeException('document_tts_requires_secure_endpoint');
        }

        $normalized = [];
        $totalCharacters = 0;
        foreach (array_slice($segments, 0, 100) as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') continue;
            $text = mb_substr($text, 0, 3000);
            $totalCharacters += mb_strlen($text);
            $normalized[] = ['text' => $text];
        }
        if ($normalized === [] || $totalCharacters > 60000) {
            throw new RuntimeException('document_tts_text_limit');
        }

        $response = Http::accept('audio/wav')
            ->asJson()
            ->withToken($token)
            ->connectTimeout(10)
            ->timeout(max(180, (int) config('services.product_supervisor.timeout', 40)))
            ->post($endpoint, [
                'language' => 'fa-IR',
                'voice_id' => (string) config('services.voice_tts.voice_id', 'farast-fa-male-v1'),
                'segments' => $normalized,
            ]);

        if (! $response->successful() || ! str_contains(strtolower((string) $response->header('Content-Type')), 'audio/wav')) {
            throw new RuntimeException('document_tts_request_failed');
        }

        $encoded = (string) $response->header('X-Farast-Segment-Timings', '');
        $normalizedHeader = strtr($encoded, '-_', '+/');
        $normalizedHeader .= str_repeat('=', (4 - strlen($normalizedHeader) % 4) % 4);
        $decoded = base64_decode($normalizedHeader, true);
        $timings = is_string($decoded) ? json_decode($decoded, true) : null;
        $audio = $response->body();
        if (! is_array($timings) || count($timings) !== count($normalized) || ! str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
            throw new RuntimeException('document_tts_invalid_response');
        }

        return ['audio' => $audio, 'timings' => $timings, 'provider' => 'piper-mana-local'];
    }
}
