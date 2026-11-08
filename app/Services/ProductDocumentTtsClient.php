<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductDocumentTtsClient
{
    public function __construct(private readonly PcmWavConcatenator $wav)
    {
    }

    public function synthesize(string $text): array
    {
        $url = trim((string) config('services.product_document_tts.url'));
        $token = trim((string) config('services.product_document_tts.token'));
        $voiceId = (string) config('services.product_document_tts.voice_id', 'farast-fa-male-v1');
        if ($url === '' || $token === '') {
            throw new RuntimeException('document_tts_not_configured');
        }
        if (mb_strlen($text) < 1 || mb_strlen($text) > 6000) {
            throw new RuntimeException('document_tts_text_limit');
        }

        $response = Http::timeout((int) config('services.product_document_tts.timeout', 120))
            ->withToken($token)
            ->accept('audio/wav')
            ->post($url, [
                'text' => $text,
                'language' => 'fa-IR',
                'voice_id' => $voiceId,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('document_tts_provider_http_'.$response->status());
        }

        return $this->wav->parse($response->body());
    }
}
