<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProductInfrastructureHealthService
{
    private const CACHE_SECONDS = 45;

    /**
     * Probe authenticated readiness endpoints without exposing secrets or waiting indefinitely.
     *
     * @return array{supervisor: array<string, mixed>, document_audio: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return [
            'supervisor' => $this->cached('supervisor', fn () => $this->probeSupervisor()),
            'document_audio' => $this->cached('document-audio', fn () => $this->probeDocumentAudio()),
        ];
    }

    private function cached(string $service, callable $probe): array
    {
        return Cache::remember(
            'farast:product-infrastructure-health:'.$service,
            now()->addSeconds(self::CACHE_SECONDS),
            $probe
        );
    }

    private function probeSupervisor(): array
    {
        $url = rtrim((string) config('services.product_supervisor.url'), '/');
        $token = trim((string) config('services.product_supervisor.token'));

        if ($url === '' || $token === '') {
            return $this->result('not_configured');
        }
        if (! $this->isSafeServiceUrl($url)) {
            return $this->result('misconfigured');
        }

        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(4)->get($url.'/v1/ready');
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
                return $this->result('unavailable');
            }

            $productReviewReady = (bool) data_get($payload, 'providers.gemini.ready_for_product_review', false);
            $fileReviewReady = (bool) data_get($payload, 'providers.groq.ready_for_file_review', false);

            return $this->result($productReviewReady && $fileReviewReady ? 'healthy' : 'degraded') + [
                'product_review_ready' => $productReviewReady,
                'file_review_ready' => $fileReviewReady,
                'privacy_gate_confirmed' => (bool) data_get($payload, 'providers.groq.privacy_gate_confirmed', false),
            ];
        } catch (Throwable) {
            return $this->result('unavailable');
        }
    }

    private function probeDocumentAudio(): array
    {
        $url = rtrim((string) config('services.document_audio.url'), '/');
        $token = trim((string) config('services.document_audio.token'));

        if ($url === '' || $token === '') {
            return $this->result('not_configured');
        }
        if (! $this->isSafeServiceUrl($url)) {
            return $this->result('misconfigured');
        }

        try {
            $response = Http::acceptJson()->withToken($token)->connectTimeout(2)->timeout(4)->get($url.'/v1/ready');
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || ($payload['status'] ?? null) !== 'ready') {
                return $this->result('unavailable');
            }

            $modelReady = (bool) ($payload['model_files_present'] ?? false);
            $alignmentReady = (bool) ($payload['word_alignment'] ?? false);

            return $this->result($modelReady && $alignmentReady ? 'healthy' : 'degraded') + [
                'model_ready' => $modelReady,
                'word_alignment_ready' => $alignmentReady,
            ];
        } catch (Throwable) {
            return $this->result('unavailable');
        }
    }

    private function result(string $status): array
    {
        return [
            'configured' => $status !== 'not_configured',
            'status' => $status,
            'checked_at' => now()->toISOString(),
        ];
    }

    private function isSafeServiceUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') === 'https') {
            return true;
        }

        return ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower((string) $parts['host']), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
