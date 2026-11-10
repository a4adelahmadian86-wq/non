<?php

namespace App\Services\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use RuntimeException;

class S3CompatibleAdapter implements FilesystemAdapter
{
    private Client $client;
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private string $bucket;
    private string $endpoint;
    private bool $pathStyle;

    public function __construct(array $config)
    {
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("S3 connection is missing {$key}.");
            }
        }

        $this->accessKey = (string) $config['key'];
        $this->secretKey = (string) $config['secret'];
        $this->region = (string) ($config['region'] ?? 'us-east-1');
        $this->bucket = (string) $config['bucket'];
        $this->endpoint = rtrim((string) $config['endpoint'], '/');
        $this->pathStyle = (bool) ($config['use_path_style_endpoint'] ?? true);
        $this->client = new Client([
            'timeout' => (float) ($config['timeout'] ?? 30),
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 8),
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    public function fileExists(string $path): bool
    {
        return $this->request('HEAD', $path)->getStatusCode() === 200;
    }

    public function directoryExists(string $path): bool
    {
        return $this->listPrefix($path, 1) !== [];
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->putStream($path, Utils::streamFor($contents), strlen($contents), hash('sha256', $contents), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw new RuntimeException('S3 writeStream expects a stream resource.');
        }

        $temporary = fopen('php://temp/maxmemory:8388608', 'w+b');
        if (! is_resource($temporary)) {
            throw new RuntimeException('Unable to allocate temporary stream.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($contents)) {
            $chunk = fread($contents, 1024 * 1024);
            if ($chunk === false) {
                fclose($temporary);
                throw new RuntimeException('Unable to read upload stream.');
            }
            if ($chunk === '') {
                continue;
            }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
            fwrite($temporary, $chunk);
        }
        rewind($temporary);

        try {
            $this->putStream($path, Utils::streamFor($temporary), $size, hash_final($hash), $config);
        } finally {
            fclose($temporary);
        }
    }

    private function putStream(string $path, $stream, int $size, string $checksum, Config $config): void
    {
        $headers = ['Content-Length' => (string) $size];
        $mime = $config->get('mimetype');
        if (is_string($mime) && $mime !== '') {
            $headers['Content-Type'] = $mime;
        }
        $response = $this->request('PUT', $path, $headers, $stream, $checksum);
        $this->assertSuccess($response->getStatusCode(), 'write', $path);
    }

    public function read(string $path): string
    {
        return (string) $this->request('GET', $path)->getBody();
    }

    public function readStream(string $path)
    {
        $response = $this->request('GET', $path);
        $stream = $response->getBody()->detach();
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open S3 response stream.');
        }
        return $stream;
    }

    public function delete(string $path): void
    {
        $response = $this->request('DELETE', $path);
        if (! in_array($response->getStatusCode(), [200, 202, 204, 404], true)) {
            $this->assertSuccess($response->getStatusCode(), 'delete', $path);
        }
    }

    public function deleteDirectory(string $path): void
    {
        foreach ($this->listPrefix($path, 1000) as $key) {
            $this->delete($key);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->write(rtrim($path, '/').'/.keep', '', $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Objects remain private; public delivery must use the application's authorization layer.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        $response = $this->request('HEAD', $path);
        $this->assertSuccess($response->getStatusCode(), 'metadata', $path);
        return new FileAttributes($path, null, 'private', null, $response->getHeaderLine('Content-Type') ?: null);
    }

    public function lastModified(string $path): FileAttributes
    {
        $response = $this->request('HEAD', $path);
        $this->assertSuccess($response->getStatusCode(), 'metadata', $path);
        $time = strtotime($response->getHeaderLine('Last-Modified') ?: '');
        return new FileAttributes($path, null, 'private', $time ?: null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $response = $this->request('HEAD', $path);
        $this->assertSuccess($response->getStatusCode(), 'metadata', $path);
        return new FileAttributes($path, (int) $response->getHeaderLine('Content-Length'), 'private');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->listPrefix($path, 1000) as $key) {
            yield new FileAttributes($key);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->write($destination, $this->read($source), $config);
    }

    private function listPrefix(string $prefix, int $limit): array
    {
        $prefix = trim($prefix, '/');
        $query = ['list-type' => '2', 'max-keys' => (string) min(1000, $limit)];
        if ($prefix !== '') {
            $query['prefix'] = $prefix.'/';
        }
        $response = $this->request('GET', '', [], null, null, $query);
        $this->assertSuccess($response->getStatusCode(), 'list', $prefix);
        $xml = @simplexml_load_string((string) $response->getBody());
        if ($xml === false) {
            throw new RuntimeException('S3 returned an invalid list response.');
        }
        $keys = [];
        foreach ($xml->Contents ?? [] as $item) {
            $key = (string) ($item->Key ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
            if (count($keys) >= $limit) {
                break;
            }
        }
        return $keys;
    }

    private function request(string $method, string $key, array $headers = [], $body = null, ?string $payloadHash = null, array $query = [])
    {
        $key = ltrim($key, '/');
        $base = parse_url($this->endpoint);
        if (! is_array($base) || empty($base['host'])) {
            throw new RuntimeException('Invalid S3 endpoint.');
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $basePath = rtrim($base['path'] ?? '', '/');
        if ($this->pathStyle) {
            $uriPath = $basePath.'/'.$this->bucket.($key !== '' ? '/'.$key : '');
        } else {
            $host = $this->bucket.'.'.$host;
            $uriPath = $basePath.($key !== '' ? '/'.$key : '/');
        }

        $uriPath = '/'.implode('/', array_map('rawurlencode', explode('/', ltrim($uriPath, '/'))));
        ksort($query);
        $queryString = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        $url = $scheme.'://'.$host.$port.$uriPath.($queryString !== '' ? '?'.$queryString : '');

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash ??= $method === 'GET' || $method === 'HEAD' || $method === 'DELETE'
            ? hash('sha256', '')
            : hash('sha256', (string) $body);

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = $host.$port;
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $now;
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name).':'.trim(preg_replace('/\s+/', ' ', (string) $value))."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalQuery = $queryString;
        $canonicalRequest = $method."\n".$uriPath."\n".$canonicalQuery."\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
        $scope = $date.'/'.$this->region.'/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n".$now."\n".$scope."\n".hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential='.$this->accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $requestHeaders[$name] = $value;
            }
        }

        return $this->client->request($method, $url, [
            'headers' => $requestHeaders,
            'body' => $body,
            'http_errors' => false,
            'connect_timeout' => 8,
        ]);
    }

    private function assertSuccess(int $status, string $operation, string $path): void
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("S3 {$operation} failed for {$path} (HTTP {$status}).");
        }
    }
}