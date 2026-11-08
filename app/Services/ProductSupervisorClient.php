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
}
