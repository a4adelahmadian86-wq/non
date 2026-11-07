<?php

namespace App\Services;

use App\Models\SiteSetting;

final class ProviderConnectionHealth
{
    /**
     * Persist a successful server-side API probe without retaining credentials
     * or raw provider responses. The fingerprint binds the result to the exact
     * credential and selected model that were tested.
     *
     * @param array<string, bool> $capabilities
     */
    public function recordSuccess(string $provider, string $key, string $model, array $capabilities): void
    {
        $prefix = $this->prefix($provider);

        SiteSetting::write($prefix.'.fingerprint', $this->fingerprint($provider, $key, $model), true);
        SiteSetting::write($prefix.'.last_success_at', now()->toIso8601String());
        SiteSetting::write($prefix.'.api_reachable', '1');
        SiteSetting::write($prefix.'.capabilities', json_encode($capabilities, JSON_UNESCAPED_SLASHES));
    }

    public function recordFailure(string $provider, string $key, string $model, string $reason, bool $apiReachable = false): void
    {
        $prefix = $this->prefix($provider);

        SiteSetting::write($prefix.'.fingerprint', $this->fingerprint($provider, $key, $model), true);
        SiteSetting::write($prefix.'.last_failure_at', now()->toIso8601String());
        SiteSetting::write($prefix.'.api_reachable', $apiReachable ? '1' : '0');
        SiteSetting::write($prefix.'.capabilities', '{}');
        SiteSetting::write($prefix.'.last_failure_code', preg_replace('/[^a-z0-9_:-]/i', '', $reason) ?: 'unknown');
    }

    /**
     * @param array<int, string> $requiredCapabilities
     * @return array{configured:bool,connection_verified:bool,api_reachable:bool,ready:bool,status:string,last_success_at:?string,last_failure_at:?string,capabilities:array<string,bool>,last_failure_code:?string}
     */
    public function state(string $provider, string $key, string $model, array $requiredCapabilities): array
    {
        $prefix = $this->prefix($provider);
        $configured = filled($key);
        $storedFingerprint = (string) SiteSetting::read($prefix.'.fingerprint', '');
        $fingerprintMatches = $configured
            && $storedFingerprint !== ''
            && hash_equals($storedFingerprint, $this->fingerprint($provider, $key, $model));

        $lastSuccessAt = SiteSetting::read($prefix.'.last_success_at');
        $lastFailureAt = SiteSetting::read($prefix.'.last_failure_at');
        $successTime = $lastSuccessAt ? strtotime((string) $lastSuccessAt) : false;
        $failureTime = $lastFailureAt ? strtotime((string) $lastFailureAt) : false;
        $freshnessSeconds = max(60, (int) config('services.provider_health_freshness_seconds', 86400));
        $successIsFresh = $successTime !== false
            && $successTime >= now()->subSeconds($freshnessSeconds)->timestamp;
        $verified = $fingerprintMatches
            && $successIsFresh
            && ($failureTime === false || $successTime > $failureTime);

        $capabilities = [];
        $storedCapabilities = json_decode((string) SiteSetting::read($prefix.'.capabilities', '{}'), true);
        if (is_array($storedCapabilities)) {
            foreach ($storedCapabilities as $name => $available) {
                if (is_string($name)) {
                    $capabilities[$name] = (bool) $available;
                }
            }
        }

        $failureIsCurrent = $fingerprintMatches
            && $failureTime !== false
            && ($successTime === false || $failureTime >= $successTime);
        $successIsStale = $fingerprintMatches
            && $successTime !== false
            && ! $successIsFresh
            && ! $failureIsCurrent;

        // Credential/model rotation, failed probes, and expired probes invalidate
        // capability claims until a fresh successful probe confirms them again.
        if (! $fingerprintMatches || $failureIsCurrent || $successIsStale) {
            $capabilities = [];
        }

        $ready = $verified;
        foreach ($requiredCapabilities as $capability) {
            $ready = $ready && ($capabilities[$capability] ?? false);
        }
        $apiReachable = $fingerprintMatches && (
            $verified
            || ($failureIsCurrent && filter_var(
                (string) SiteSetting::read($prefix.'.api_reachable', '0'),
                FILTER_VALIDATE_BOOLEAN
            ))
        );

        $status = ! $configured
            ? 'unavailable'
            : ($failureIsCurrent
                ? 'degraded'
                : ($successIsStale ? 'stale' : (! $verified ? 'configured' : ($ready ? 'healthy' : 'degraded'))));

        return [
            'configured' => $configured,
            'connection_verified' => $verified,
            'api_reachable' => $apiReachable,
            'ready' => $ready,
            'status' => $status,
            'last_success_at' => $fingerprintMatches && $successTime !== false ? (string) $lastSuccessAt : null,
            'last_failure_at' => $fingerprintMatches && $lastFailureAt ? (string) $lastFailureAt : null,
            'capabilities' => $capabilities,
            'last_failure_code' => $failureIsCurrent ? (string) SiteSetting::read($prefix.'.last_failure_code', 'unknown') : null,
        ];
    }

    private function fingerprint(string $provider, string $key, string $model): string
    {
        return hash_hmac(
            'sha256',
            $provider."\0".$key."\0".$model,
            (string) config('app.key')
        );
    }

    private function prefix(string $provider): string
    {
        if (! in_array($provider, ['gemini', 'groq'], true)) {
            throw new \InvalidArgumentException('provider_health_not_supported');
        }

        return 'provider_health.'.$provider;
    }
}
