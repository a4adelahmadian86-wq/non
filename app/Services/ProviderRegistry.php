<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\VoiceProviderAccount;
use App\Services\AI\AiProviderRegistry;

final class ProviderRegistry
{
    public function __construct(
        private AiProviderRegistry $ai,
        private VoiceProviderRouter $voice,
        private ProviderConnectionHealth $connectionHealth,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        $items = [];

        foreach (VoiceProviderAccount::query()->orderBy('priority')->orderBy('provider')->get() as $account) {
            $successTime = $account->last_success_at ? strtotime((string) $account->last_success_at) : false;
            $failureTime = $account->last_failure_at ? strtotime((string) $account->last_failure_at) : false;
            $configured = $account->credentialsConfigured();
            $failedMostRecently = $failureTime !== false
                && ($successTime === false || $failureTime >= $successTime);
            $freshnessSeconds = max(60, (int) config('services.provider_health_freshness_seconds', 86400));
            $successIsFresh = $successTime !== false
                && $successTime >= now()->subSeconds($freshnessSeconds)->timestamp;
            $verified = $successIsFresh && ! $failedMostRecently;
            $stale = $successTime !== false && ! $successIsFresh && ! $failedMostRecently;
            $status = ! $account->enabled
                ? 'disabled'
                : (! $configured
                    ? 'unconfigured'
                    : ($failedMostRecently ? 'degraded' : ($stale ? 'stale' : (! $verified ? 'unverified' : ($account->isAvailable() ? 'healthy' : 'degraded')))));

            $items[] = [
                'id' => 'voice:'.$account->id,
                'name' => $account->name ?: $account->provider,
                'provider' => $account->provider,
                'type' => 'voice',
                'model' => $account->model,
                'capabilities' => array_values(array_filter(array_merge(['speech.stt'], (array) ($account->capabilities['capabilities'] ?? [])))),
                'priority' => (int) $account->priority,
                'status' => $status,
                'connection_verified' => $verified,
                'api_reachable' => $verified,
                'ready' => $configured && $account->isAvailable() && $verified,
                'last_success_at' => $account->last_success_at?->toIso8601String(),
                'last_failure_at' => $account->last_failure_at?->toIso8601String(),
                'last_failure_code' => null,
                'quota' => [
                    'used' => (float) $account->quota_used_seconds,
                    'limit' => $account->quota_limit_seconds === null ? null : (float) $account->quota_limit_seconds,
                ],
                'latency_ms' => $account->last_latency_ms,
                'reliability' => (int) $account->reliability_score,
                'quality' => (int) $account->quality_score,
                'credential_configured' => $configured,
                'fallback' => false,
            ];
        }

        foreach ($this->ai->capabilities() as $name => $capabilities) {
            if ($name !== 'farast-ai-gateway') {
                continue;
            }

            $url = rtrim(trim((string) (SiteSetting::read('ai_gateway_url') ?: config('services.ai_gateway.url', ''))), '/');
            $key = trim((string) (SiteSetting::read('ai_gateway_token') ?: config('services.ai_gateway.token', '')));
            $model = '@cf/openai/gpt-oss-20b';
            $health = $this->connectionHealth->state(
                'farast-ai-gateway',
                $key,
                $model,
                ['text' => true, 'review' => true, 'vision' => true, 'audio' => true]
            );

            $configured = $url !== '' && $key !== '';
            if (! $configured) {
                $health['configured'] = false;
                $health['connection_verified'] = false;
                $health['api_reachable'] = false;
                $health['ready'] = false;
                $health['status'] = 'unavailable';
            }

            $items[] = [
                'id' => 'ai:farast-ai-gateway',
                'name' => 'FARAST AI Gateway',
                'provider' => 'farast-ai-gateway',
                'type' => 'ai',
                'model' => $model,
                'capabilities' => ['ai.text', 'ai.review', 'ai.vision', 'ai.audio', 'ai.structured_output'],
                'priority' => 0,
                'status' => $health['status'],
                'connection_verified' => $health['connection_verified'],
                'api_reachable' => $health['api_reachable'],
                'ready' => $health['ready'],
                'capability_readiness' => [
                    'ai.text' => (bool) ($health['capabilities']['text'] ?? false),
                    'ai.review' => (bool) ($health['capabilities']['review'] ?? false),
                    'ai.vision' => (bool) ($health['capabilities']['vision'] ?? false),
                    'ai.audio' => (bool) ($health['capabilities']['audio'] ?? false),
                ],
                'last_success_at' => $health['last_success_at'],
                'last_failure_at' => $health['last_failure_at'],
                'last_failure_code' => $health['last_failure_code'] ?? null,
                'quota' => null,
                'latency_ms' => null,
                'reliability' => null,
                'quality' => null,
                'credential_configured' => $health['configured'],
                'fallback' => false,
            ];
        }

        return $items;
    }

    public function forCapability(string $capability, ?string $locale = null): mixed
    {
        if (in_array($capability, ['speech.stt', 'voice'], true)) {
            return $this->voice->best($locale ?: 'fa-IR');
        }
        if (str_starts_with($capability, 'ai.')) {
            return $this->ai->forOperation(['capability' => $capability]);
        }

        throw new \InvalidArgumentException('provider_capability_not_supported');
    }
}
