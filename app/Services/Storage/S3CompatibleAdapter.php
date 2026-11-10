<?php

namespace App\Services\Storage;

use App\Models\StorageVolume;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class S3CompatibleAdapter
{
    public function putFile(StorageVolume $volume, string $key, string $localPath): void
    {
        $this->request($volume, 'PUT', $key, [], fopen($localPath, 'rb'), 'UNSIGNED-PAYLOAD', [
            'Content-Length' => (string) filesize($localPath),
        ]);
    }

    public function putContents(StorageVolume $volume, string $key, string $contents): void
    {
        $this->request($volume, 'PUT', $key, [], $contents, hash('sha256', $contents));
    }

    public function getContents(StorageVolume $volume, string $key): string
    {
        return (string) $this->request($volume, 'GET', $key)->getBody();
    }

    public function downloadTo(StorageVolume $volume, string $key, string $target): void
    {
        $response = $this->request($volume, 'GET', $key, [], null, 'UNSIGNED-PAYLOAD', [], ['stream' => true]);
        $input = $response->getBody()->detach();
        $output = fopen($target, 'wb');
        if (! is_resource($input) || ! is_resource($output)) {
            throw new RuntimeException('امکان ایجاد جریان دریافت فایل وجود ندارد.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('دریافت کامل فایل از فضای ذخیره‌سازی انجام نشد.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function exists(StorageVolume $volume, string $key): bool
    {
        try {
            $this->request($volume, 'HEAD', $key);
            return true;
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'HTTP 404')) return false;
            throw $exception;
        }
    }

    public function delete(StorageVolume $volume, string $key): void
    {
        $this->request($volume, 'DELETE', $key);
    }

    public function probe(StorageVolume $volume): array
    {
        $key = '.farast-health/'.bin2hex(random_bytes(16)).'.txt';
        $payload = 'farast-storage-probe:'.now()->toIso8601String();
        $this->putContents($volume, $key, $payload);
        try {
            $read = $this->getContents($volume, $key);
            if (! hash_equals($payload, $read)) {
                throw new RuntimeException('محتوای خوانده‌شده با فایل آزمایشی یکسان نیست.');
            }
        } finally {
            $this->delete($volume, $key);
        }
        return ['read' => true, 'write' => true, 'delete' => true];
    }

    private function request(
        StorageVolume $volume,
        string $method,
        string $key,
        array $query = [],
        mixed $body = null,
        ?string $payloadHash = null,
        array $extraHeaders = [],
        array $options = []
    ) {
        $credentials = (array) ($volume->credentials ?? []);
        $accessKey = (string) ($credentials['key'] ?? '');
        $secretKey = (string) ($credentials['secret'] ?? '');
        $bucket = (string) ($volume->bucket ?: ($credentials['bucket'] ?? ''));
        $endpoint = rtrim((string) ($volume->endpoint ?: ($credentials['endpoint'] ?? '')), '/');
        $region = (string) ($volume->region ?: ($credentials['region'] ?? 'us-east-1'));

        if ($accessKey === '' || $secretKey === '' || $bucket === '' || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('اطلاعات دسترسی S3 ناقص است.');
        }

        $pathStyle = (bool) ($credentials['use_path_style_endpoint'] ?? true);
        $base = parse_url($endpoint);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $basePath = rtrim($base['path'] ?? '', '/');
        $objectPath = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        if ($pathStyle) {
            $uriPath = $basePath.'/'.rawurlencode($bucket).'/'.$objectPath;
        } else {
            $host = $bucket.'.'.$host;
            $uriPath = $basePath.'/'.$objectPath;
        }
        $uriPath = '/'.ltrim($uriPath, '/');
        $queryPairs = [];
        foreach ($query as $name => $value) $queryPairs[(string) $name] = (string) $value;
        ksort($queryPairs);
        $canonicalQuery = implode('&', array_map(
            fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v),
            array_keys($queryPairs),
            array_values($queryPairs)
        ));
        $url = $scheme.'://'.$host.$port.$uriPath.($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
        $now = now()->utc();
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payloadHash ??= hash('sha256', (string) $body);
        $headers = [
            'host' => $host.$port,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if (! empty($credentials['session_token'])) $headers['x-amz-security-token'] = $credentials['session_token'];
        foreach ($extraHeaders as $name => $value) $headers[strtolower($name)] = trim((string) $value);
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) $canonicalHeaders .= $name.':'.$value."\n";
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = $method."\n".$uriPath."\n".$canonicalQuery."\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
        $scope = $date.'/'.$region.'/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secretKey, true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential='.$accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
        $headers['accept'] = '*/*';

        try {
            $client = new Client(['connect_timeout' => 8, 'timeout' => 120, 'http_errors' => false, 'verify' => true]);
            $response = $client->request($method, $url, array_merge($options, ['headers' => array_merge($headers, $extraHeaders), 'body' => $body]));
        } catch (GuzzleException $exception) {
            throw new RuntimeException('ارتباط با فضای ذخیره‌سازی برقرار نشد: '.$exception->getMessage(), 0, $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('پاسخ نامعتبر از فضای ذخیره‌سازی: HTTP '.$status);
        }
        return $response;
    }
}