<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductSupervisorClient
{
    public function configured(): bool
    {
        return filled(config('services.product_supervisor.url'))
            && filled(config('services.product_supervisor.token'));
    }

    public function review(array $snapshot): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('product_supervisor_not_configured');
        }

        $baseUrl = rtrim((string) config('services.product_supervisor.url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('product_supervisor_requires_https');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken((string) config('services.product_supervisor.token'))
            ->connectTimeout(8)
            ->timeout((int) config('services.product_supervisor.timeout', 40))
            ->post($baseUrl.'/v1/reviews/products', $snapshot);

        if (! $response->successful()) {
            throw new RuntimeException('product_supervisor_request_failed');
        }

        $payload = $response->json();
        if (! is_array($payload)
            || ! isset($payload['review_id'], $payload['product_id'], $payload['verdict'], $payload['score'], $payload['findings'])
            || (string) $payload['product_id'] !== (string) ($snapshot['product_id'] ?? '')
            || ! in_array($payload['verdict'], ['approved', 'needs_changes', 'blocked'], true)
            || ! is_array($payload['findings'])) {
            throw new RuntimeException('product_supervisor_invalid_response');
        }

        $payload['automated_publication'] = false;

        return $payload;
    }

    public function reviewFile($stream, string $filename, array $metadata): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('product_supervisor_not_configured');
        }
        if (! is_resource($stream)) {
            throw new RuntimeException('product_supervisor_file_stream_unavailable');
        }

        $baseUrl = rtrim((string) config('services.product_supervisor.url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('product_supervisor_requires_https');
        }

        $response = Http::acceptJson()
            ->withToken((string) config('services.product_supervisor.token'))
            ->connectTimeout(8)
            ->timeout(max(180, (int) config('services.product_supervisor.timeout', 40)))
            ->attach('file', $stream, basename($filename))
            ->post($baseUrl.'/v1/reviews/files', $metadata);

        if (! $response->successful()) {
            throw new RuntimeException('product_supervisor_file_review_failed');
        }

        $payload = $response->json();
        if (! is_array($payload)
            || ! isset($payload['review_id'], $payload['product_id'], $payload['inspection'], $payload['security_gate'])
            || (string) $payload['product_id'] !== (string) ($metadata['product_id'] ?? '')
            || ! is_array($payload['inspection'])
            || ! in_array($payload['security_gate'], ['blocked', 'manual_scan_required', 'structural_checks_passed', 'upstream_scan_verified'], true)) {
            throw new RuntimeException('product_supervisor_invalid_file_response');
        }

        $payload['publication_authorized'] = false;

        return $payload;
    }

    public function createPdfSample($stream, string $filename, array $selectedPages): array
    {
        if (! $this->configured() || ! is_resource($stream)) {
            throw new RuntimeException('product_supervisor_sample_unavailable');
        }

        $pages = array_values(array_unique(array_filter(
            array_map('intval', array_slice($selectedPages, 0, 10)),
            fn ($page) => $page > 0
        )));
        if ($pages === [] || count($pages) > 10) {
            throw new RuntimeException('product_supervisor_sample_pages_invalid');
        }

        $baseUrl = rtrim((string) config('services.product_supervisor.url'), '/');
        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('product_supervisor_requires_https');
        }

        $response = Http::accept('application/pdf')
            ->withToken((string) config('services.product_supervisor.token'))
            ->connectTimeout(8)
            ->timeout(max(120, (int) config('services.product_supervisor.timeout', 40)))
            ->attach('file', $stream, basename($filename))
            ->post($baseUrl.'/v1/documents/samples/pdf', [
                'selected_pages_json' => json_encode($pages, JSON_THROW_ON_ERROR),
            ]);

        $bytes = $response->body();
        if (! $response->successful()
            || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/pdf')
            || ! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('product_supervisor_sample_generation_failed');
        }

        return [
            'bytes' => $bytes,
            'pages' => $pages,
            'mime' => 'application/pdf',
        ];
    }

}
