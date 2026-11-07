<?php

namespace App\Services;

use App\Models\CanvaConnection;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CanvaService
{
    private const API = 'https://api.canva.com/rest/v1';
    private const TOKEN_URL = 'https://api.canva.com/rest/v1/oauth/token';

    public function configured(): bool
    {
        return filled(SiteSetting::read('canva_client_id'))
            && filled(SiteSetting::read('canva_client_secret'))
            && filled(SiteSetting::read('canva_redirect_uri'));
    }

    public function authorizeUrl(User $user): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('canva_not_configured');
        }

        $verifier = Str::random(64);
        $state = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        session(['canva_oauth' => ['state' => $state, 'verifier' => $verifier]]);

        $scope = (string) SiteSetting::read(
            'canva_scopes',
            'design:meta:read design:content:read design:content:write asset:read asset:write'
        );
        $query = http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 's256',
            'scope' => $scope,
            'response_type' => 'code',
            'client_id' => SiteSetting::read('canva_client_id'),
            'state' => $state,
            'redirect_uri' => SiteSetting::read('canva_redirect_uri'),
        ]);

        return ['url' => 'https://www.canva.com/api/oauth/authorize?'.$query, 'state' => $state];
    }

    public function callback(User $user, string $code, string $state): CanvaConnection
    {
        $oauth = session()->pull('canva_oauth');
        if (! $oauth || ! hash_equals((string) ($oauth['state'] ?? ''), $state)) {
            throw new RuntimeException('canva_oauth_state_invalid');
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) SiteSetting::read('canva_client_id'), (string) SiteSetting::read('canva_client_secret'))
                ->connectTimeout(5)
                ->timeout(15)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => SiteSetting::read('canva_redirect_uri'),
                    'code_verifier' => $oauth['verifier'],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('canva_token_exchange_unavailable');
        }

        if (! $response->successful()) {
            throw new RuntimeException('canva_token_exchange_failed');
        }

        $tokens = $response->json();
        if (! is_array($tokens) || ! filled($tokens['access_token'] ?? null) || ! filled($tokens['refresh_token'] ?? null)) {
            throw new RuntimeException('canva_token_exchange_invalid_response');
        }

        return CanvaConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 14400))),
                'scopes' => preg_split('/\s+/', trim((string) ($tokens['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY),
                'status' => 'connected',
                'last_checked_at' => now(),
            ]
        );
    }

    public function token(CanvaConnection $connection): string
    {
        if ($connection->status === 'reauthorize') {
            throw new RuntimeException('canva_reauthorization_required');
        }

        if ($connection->expires_at
            && $connection->expires_at->gt(now()->addMinute())
            && filled($connection->access_token)) {
            return $connection->access_token;
        }

        // Canva rotates refresh tokens. Serialize refreshes per connection and
        // re-check the token after acquiring the row lock so parallel requests
        // cannot consume the same refresh token.
        $result = DB::transaction(function () use ($connection): array {
            $locked = CanvaConnection::query()
                ->whereKey($connection->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return ['error' => 'canva_connection_not_found'];
            }

            if ($locked->status === 'reauthorize') {
                return ['error' => 'canva_reauthorization_required'];
            }

            if ($locked->expires_at
                && $locked->expires_at->gt(now()->addMinute())
                && filled($locked->access_token)) {
                return ['token' => $locked->access_token];
            }

            if (! filled($locked->refresh_token)) {
                $locked->status = 'reauthorize';
                $locked->last_checked_at = now();
                $locked->save();

                return ['error' => 'canva_reauthorization_required'];
            }

            try {
                $response = Http::asForm()
                    ->withBasicAuth((string) SiteSetting::read('canva_client_id'), (string) SiteSetting::read('canva_client_secret'))
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->post(self::TOKEN_URL, [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $locked->refresh_token,
                    ]);
            } catch (ConnectionException) {
                $locked->status = 'degraded';
                $locked->last_checked_at = now();
                $locked->save();

                return ['error' => 'canva_token_refresh_unavailable'];
            }

            if (! $response->successful()) {
                $locked->status = in_array($response->status(), [400, 401], true) ? 'reauthorize' : 'degraded';
                $locked->last_checked_at = now();
                $locked->save();

                return ['error' => $locked->status === 'reauthorize'
                    ? 'canva_reauthorization_required'
                    : 'canva_token_refresh_failed'];
            }

            $tokens = $response->json();
            if (! is_array($tokens) || ! filled($tokens['access_token'] ?? null) || ! filled($tokens['refresh_token'] ?? null)) {
                $locked->status = 'reauthorize';
                $locked->last_checked_at = now();
                $locked->save();

                return ['error' => 'canva_token_refresh_invalid_response'];
            }

            $locked->access_token = $tokens['access_token'];
            $locked->refresh_token = $tokens['refresh_token'];
            $locked->expires_at = now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 14400)));
            $locked->last_checked_at = now();
            $locked->status = 'connected';
            $locked->save();

            return ['token' => $locked->access_token];
        }, 3);

        if (! filled($result['token'] ?? null)) {
            throw new RuntimeException((string) ($result['error'] ?? 'canva_token_refresh_failed'));
        }

        return (string) $result['token'];
    }

    public function request(CanvaConnection $connection, string $method, string $path, array $options = []): array
    {
        $token = $this->token($connection);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(30)
                ->send($method, self::API.$path, $options);
        } catch (ConnectionException) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_api_unavailable');
        }

        if ($response->status() === 401) {
            $this->markStatus($connection, 'reauthorize');
            throw new RuntimeException('canva_unauthorized');
        }

        if (! $response->successful()) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_api_http_'.$response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_api_invalid_response');
        }

        $this->markStatus($connection, 'connected');

        return $data;
    }

    public function designs(User $user, array $query = []): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();
        $queryString = http_build_query($query);

        return $this->request($connection, 'GET', '/designs'.($queryString ? '?'.$queryString : ''));
    }

    public function design(User $user, string $id): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();

        return $this->request($connection, 'GET', '/designs/'.rawurlencode($id));
    }

    public function createDoc(User $user, string $title): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();

        return $this->request($connection, 'POST', '/designs', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'type' => 'type_and_asset',
                'design_type' => ['type' => 'preset', 'name' => 'doc'],
                'title' => $title,
            ],
        ]);
    }

    public function export(User $user, string $designId, string $type = 'pdf'): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();

        return $this->request($connection, 'POST', '/exports', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['design_id' => $designId, 'format' => ['type' => $type]],
        ]);
    }

    public function import(User $user, string $bytes, string $title, string $mime): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();
        $token = $this->token($connection);
        $metadata = json_encode([
            'title_base64' => base64_encode(mb_substr($title, 0, 50)),
            'mime_type' => $mime,
        ], JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Content-Type' => 'application/octet-stream', 'Import-Metadata' => $metadata])
                ->withBody($bytes, 'application/octet-stream')
                ->connectTimeout(5)
                ->timeout(120)
                ->post(self::API.'/imports');
        } catch (ConnectionException) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_import_unavailable');
        }

        if ($response->status() === 401) {
            $this->markStatus($connection, 'reauthorize');
            throw new RuntimeException('canva_unauthorized');
        }

        if (! $response->successful()) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_import_http_'.$response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            $this->markStatus($connection, 'degraded');
            throw new RuntimeException('canva_import_invalid_response');
        }

        $this->markStatus($connection, 'connected');

        return $data;
    }

    public function importStatus(User $user, string $jobId): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();

        return $this->request($connection, 'GET', '/imports/'.rawurlencode($jobId));
    }

    public function exportStatus(User $user, string $jobId): array
    {
        $connection = CanvaConnection::where('user_id', $user->id)->firstOrFail();

        return $this->request($connection, 'GET', '/exports/'.rawurlencode($jobId));
    }

    private function markStatus(CanvaConnection $connection, string $status): void
    {
        CanvaConnection::query()
            ->whereKey($connection->getKey())
            ->update([
                'status' => $status,
                'last_checked_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
