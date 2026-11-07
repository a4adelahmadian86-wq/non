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
            $verified = (bool) $account->last_success_at
                && (! $account->last_failure_at || $account->last_success_at->gt($account->last_failure_at));
            $configured = $account->credentialsConfigured();
            $failedMostRecently = $account->last_failure_at
                && (! $account->last_success_at || $account->last_failure_at->gte($account->last_success_at));
            $status = ! $account->enabled
                ? 'disabled'
                : (! $configured
                    ? 'unconfigured'
                    : ($failedMostRecently ? 'degraded' : (! $verified ? 'unverified' : ($account->isAvailable() ? 'healthy' : 'degraded'))));

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
            $key = $name === 'gemini'
                ? trim((string) (SiteSetting::read('gemini_api_key') ?: config('services.gemini.key', '')))
                : '';
            $model = $name === 'gemini'
                ? trim((string) (SiteSetting::read('gemini_model') ?: config('services.gemini.model', 'gemini-3.8-flash')))
                : '';
            $health = $name === 'gemini'
                ? $this->connectionHealth->state('gemini', $key, $model, ['text'])
                : [
                    'configured' => false,
                    'connection_verified' => false,
                    'api_reachable' => false,
                    'ready' => false,
                    'status' => 'unavailable',
                    'last_success_at' => null,
                    'last_failure_at' => null,
                    'capabilities' => [],
                ];

            $items[] = [
                'id' => 'ai:'.$name,
                'name' => $name,
                'provider' => $name,
                'type' => 'ai',
                'model' => $model ?: null,
                'capabilities' => array_values(array_unique(array_merge(['ai.text'], array_keys((array) $capabilities)))),
                'priority' => 0,
                'status' => $health['status'],
                'connection_verified' => $health['connection_verified'],
                'api_reachable' => $health['api_reachable'],
                'ready' => $health['ready'],
                'last_success_at' => $health['last_success_at'],
                'last_failure_at' => $health['last_failure_at'],
                'quota' => null,
                'latency_ms' => null,
                'reliability' => null,
                'quality' => null,
                'credential_configured' => $health['configured'],
                'fallback' => false,
            ];
        }

        $groqKey = trim((string) (SiteSetting::read('voice_groq_key') ?: config('services.groq.key', '')));
        $groqModel = trim((string) (SiteSetting::read('assistant_groq_model') ?: 'openai/gpt-oss-120b'));
        $groqHealth = $this->connectionHealth->state('groq', $groqKey, $groqModel, ['chat', 'speech']);

        $items[] = [
            'id' => 'ai:groq',
            'name' => 'Groq',
            'provider' => 'groq',
            'type' => 'ai',
            'model' => $groqModel,
            'capabilities' => ['ai.text', 'speech.stt'],
            'priority' => 0,
            'status' => $groqHealth['status'],
            'connection_verified' => $groqHealth['connection_verified'],
            'api_reachable' => $groqHealth['api_reachable'],
            'ready' => $groqHealth['ready'],
            'last_success_at' => $groqHealth['last_success_at'],
            'last_failure_at' => $groqHealth['last_failure_at'],
            'quota' => null,
            'latency_ms' => null,
            'reliability' => null,
            'quality' => null,
            'credential_configured' => $groqHealth['configured'],
            'fallback' => false,
        ];

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
