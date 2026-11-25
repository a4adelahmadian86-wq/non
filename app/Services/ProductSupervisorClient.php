<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductSupervisorClient
{
    /**
     * A separately hosted domestic node can be configured as fallback so this
     * pipeline can continue when international routing is unavailable.
     */
    public function configured(): bool
    {
        return $this->endpoints() !== [];
    }

    public function review(array $snapshot): array
    {
        $endpoints = $this->endpoints();
        if ($endpoints === []) {
            throw new RuntimeException('product_supervisor_not_configured');
        }

        foreach ($endpoints as $index => $endpoint) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withToken($endpoint['token'])
                    ->connectTimeout(8)
                    ->timeout((int) config('services.product_supervisor.timeout', 40))
                    ->post($endpoint['url'].'/v1/reviews/products', $snapshot);

                if ($response->serverError() && isset($endpoints[$index + 1])) {
                    continue;
                }
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
            } catch (ConnectionException $exception) {
                if (isset($endpoints[$index + 1])) {
                    continue;
                }
                throw new RuntimeException('product_supervisor_unavailable', 0, $exception);
            }
        }

        throw new RuntimeException('product_supervisor_unavailable');
    }

    public function reviewFile($stream, string $filename, array $metadata, $readerSourceStream = null, ?string $readerSourceFilename = null): array
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('product_supervisor_file_stream_unavailable');
        }

        $endpoints = $this->endpoints();
        if ($endpoints === []) {
            throw new RuntimeException('product_supervisor_not_configured');
        }

        foreach ($endpoints as $index => $endpoint) {
            // A failed remote attempt may have consumed the streams. Rewind before
            // retrying on the fallback node; never upload a partial document.
            if ($index > 0) {
                if (! @rewind($stream)) {
                    throw new RuntimeException('product_supervisor_file_stream_not_rewindable');
                }
                if (is_resource($readerSourceStream) && ! @rewind($readerSourceStream)) {
                    throw new RuntimeException('product_supervisor_reader_source_stream_not_rewindable');
                }
            }

            try {
                $request = Http::acceptJson()
                    ->withToken($endpoint['token'])
                    // Fail over quickly when international routing is unavailable;
                    // the overall response timeout still allows long document analysis.
                    ->connectTimeout($index === 0 ? 8 : 12)
                    ->timeout(max(300, (int) config('services.product_supervisor.file_timeout', 540)))
                    ->attach('file', $stream, basename($filename));

                if (is_resource($readerSourceStream) && filled($readerSourceFilename)) {
                    $request->attach('reader_source_file', $readerSourceStream, basename((string) $readerSourceFilename));
                }

                $response = $request->post($endpoint['url'].'/v1/reviews/files', $metadata);

                if ($response->serverError() && isset($endpoints[$index + 1])) {
                    continue;
                }
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
            } catch (ConnectionException $exception) {
                if (isset($endpoints[$index + 1])) {
                    continue;
                }
                throw new RuntimeException('product_supervisor_unavailable', 0, $exception);
            }
        }

        throw new RuntimeException('product_supervisor_unavailable');
    }

    private function isPrivateServiceUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) return false;

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') return false;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        if ($host === 'localhost' || ! str_contains($host, '.')) return true;

        return preg_match('/\\.(?:internal|local|localhost|test|svc|cluster\\.local)$/D', $host) === 1;
    }

    /**
     * Return only explicitly configured HTTPS endpoints with their own bearer tokens.
     * The fallback is optional; no endpoint or secret is guessed.
     */
    private function endpoints(): array
    {
        $configured = [
            [
                'url' => config('services.product_supervisor.url'),
                'token' => config('services.product_supervisor.token'),
            ],
            [
                'url' => config('services.product_supervisor.fallback_url'),
                'token' => config('services.product_supervisor.fallback_token'),
            ],
        ];

        $endpoints = [];
        $seen = [];
        $localOnly = (bool) config('services.voice.local_only', false);
        foreach ($configured as $endpoint) {
            $url = rtrim(trim((string) ($endpoint['url'] ?? '')), '/');
            $token = trim((string) ($endpoint['token'] ?? ''));

            if ($url === '' || $token === '' || isset($seen[$url])) {
                continue;
            }
            if ($localOnly && ! $this->isPrivateServiceUrl($url)) {
                continue;
            }
            if (! str_starts_with($url, 'https://')) {
                throw new RuntimeException('product_supervisor_requires_https');
            }

            $seen[$url] = true;
            $endpoints[] = ['url' => $url, 'token' => $token];
        }

        return $endpoints;
    }
}
