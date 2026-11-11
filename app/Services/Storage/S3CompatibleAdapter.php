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
        $size = (int) filesize($localPath);
        if ($size > 5 * 1024 * 1024 * 1024) {
            $this->multipartUpload($volume, $key, $localPath, $size);
            return;
        }

        $stream = fopen($localPath, 'rb');
        if (! is_resource($stream)) throw new RuntimeException('فایل مبدأ برای آپلود قابل خواندن نیست.');
        try {
            $this->request($volume, 'PUT', $key, [], $stream, 'UNSIGNED-PAYLOAD', [
                'Content-Length' => (string) $size,
                'Content-Type' => mime_content_type($localPath) ?: 'application/octet-stream',
            ]);
        } finally {
            fclose($stream);
        }
    }

    private function multipartUpload(StorageVolume $volume, string $key, string $localPath, int $size): void
    {
        $init = $this->request($volume, 'POST', $key, ['uploads' => '']);
        $xml = simplexml_load_string((string) $init->getBody(), \SimpleXMLElement::class, LIBXML_NONET);
        $uploadId = $xml ? (string) ($xml->UploadId ?? '') : '';
        if ($uploadId === '') throw new RuntimeException('شناسه آپلود چندبخشی از سرویس‌دهنده دریافت نشد.');

        $stream = fopen($localPath, 'rb');
        if (! is_resource($stream)) throw new RuntimeException('فایل مبدأ برای آپلود چندبخشی قابل خواندن نیست.');
        $parts = [];
        $partSize = 64 * 1024 * 1024;
        try {
            $partNumber = 1;
            while (! feof($stream)) {
                if ($partNumber > 10000) throw new RuntimeException('تعداد بخش‌های آپلود از سقف S3 بیشتر شد.');
                $part = fread($stream, $partSize);
                if (! is_string($part) || $part === '') break;
                $partStream = fopen('php://temp', 'w+b');
                fwrite($partStream, $part);
                rewind($partStream);
                $response = $this->request($volume, 'PUT', $key, [
                    'partNumber' => (string) $partNumber,
                    'uploadId' => $uploadId,
                ], $partStream, 'UNSIGNED-PAYLOAD', ['Content-Length' => (string) strlen($part)]);
                fclose($partStream);
                $etag = trim((string) $response->getHeaderLine('ETag'), '"');
                if ($etag === '') throw new RuntimeException('ETag بخش آپلودشده دریافت نشد.');
                $parts[] = ['number' => $partNumber, 'etag' => $etag];
                $partNumber++;
            }
            if ($parts === []) throw new RuntimeException('فایل بکاپ خالی است.');
            $completion = '<CompleteMultipartUpload>'.implode('', array_map(
                fn ($part) => '<Part><PartNumber>'.$part['number'].'</PartNumber><ETag>'.htmlspecialchars('"'.$part['etag'].'"', ENT_XML1).'</ETag></Part>',
                $parts
            )).'</CompleteMultipartUpload>';
            $this->request($volume, 'POST', $key, ['uploadId' => $uploadId], $completion, hash('sha256', $completion), ['Content-Type' => 'application/xml']);
        } catch (\Throwable $exception) {
            try {
                $this->request($volume, 'DELETE', $key, ['uploadId' => $uploadId]);
            } catch (\Throwable $abortException) {
                report($abortException);
            }
            throw $exception;
        } finally {
            fclose($stream);
        }
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

    public function size(StorageVolume $volume, string $key): int
    {
        $response = $this->request($volume, 'HEAD', $key);
        $size = $response->getHeaderLine('Content-Length');
        if ($size === '' || ! ctype_digit($size)) {
            throw new RuntimeException('اندازه شیء از سرویس ذخیره‌سازی دریافت نشد.');
        }
        return (int) $size;
    }

    public function delete(StorageVolume $volume, string $key): void
    {
        $this->request($volume, 'DELETE', $key);
    }

    public function inventory(StorageVolume $volume): array
    {
        $count = 0;
        $bytes = 0;
        $token = null;
        for ($page = 0; $page < 10000; $page++) {
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($token !== null) $query['continuation-token'] = $token;
            $xml = (string) $this->request($volume, 'GET', '', $query)->getBody();
            $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($document === false) throw new RuntimeException('پاسخ فهرست اشیای S3 قابل پردازش نیست.');
            foreach ($document->xpath('//*[local-name()="Contents"]') ?: [] as $object) {
                $count++;
                $bytes += (int) ($object->Size ?? 0);
            }
            $truncated = (string) (($document->xpath('//*[local-name()="IsTruncated"]')[0] ?? 'false'));
            $next = $document->xpath('//*[local-name()="NextContinuationToken"]');
            $token = $next && isset($next[0]) ? (string) $next[0] : null;
            if (strtolower($truncated) !== 'true' || $token === null || $token === '') break;
        }
        return ['object_count' => $count, 'used_bytes' => $bytes];
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
        if ($key !== '') $key = StoragePath::normalizeObjectKey($key);

        $credentials = (array) ($volume->credentials ?? []);
        $accessKey = (string) ($credentials['key'] ?? '');
        $secretKey = (string) ($credentials['secret'] ?? '');
        $bucket = (string) ($volume->bucket ?: ($credentials['bucket'] ?? ''));
        $endpoint = rtrim((string) ($volume->endpoint ?: ($credentials['endpoint'] ?? '')), '/');
        $region = (string) ($volume->region ?: ($credentials['region'] ?? 'us-east-1'));

        $parsedEndpoint = parse_url($endpoint);
        if ($accessKey === '' || $secretKey === '' || $bucket === ''
            || ! is_array($parsedEndpoint)
            || strtolower((string) ($parsedEndpoint['scheme'] ?? '')) !== 'https'
            || empty($parsedEndpoint['host'])
            || isset($parsedEndpoint['user'])
            || isset($parsedEndpoint['pass'])
            || isset($parsedEndpoint['query'])
            || isset($parsedEndpoint['fragment'])) {
            throw new RuntimeException('اطلاعات دسترسی یا Endpoint امن S3 ناقص یا نامعتبر است.');
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
            $connectTimeout = max(1, (int) config('storage_management.health.connect_timeout', 8));
            $requestTimeout = max($connectTimeout, (int) config('storage_management.health.request_timeout', 300));
            $client = new Client([
                'connect_timeout' => $connectTimeout,
                'timeout' => $requestTimeout,
                'http_errors' => false,
                'verify' => true,
                // A redirect would forward a signed request to a different host.
                'allow_redirects' => false,
            ]);
            $response = $client->request($method, $url, array_merge($options, ['headers' => $headers, 'body' => $body]));
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