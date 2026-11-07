<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\VoiceProviderAccount;
use App\Services\AI\AiProviderRegistry;

final class ProviderRegistry
{
    public function __construct(private AiProviderRegistry $ai, private VoiceProviderRouter $voice) {}

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        $items=[];
        foreach (VoiceProviderAccount::query()->orderBy('priority')->orderBy('provider')->get() as $a) {
            $items[]=[
                'id'=>'voice:'.$a->id,'name'=>$a->name ?: $a->provider,'provider'=>$a->provider,'type'=>'voice',
                'model'=>$a->model,'capabilities'=>array_values(array_filter(array_merge(['speech.stt'],(array)($a->capabilities['capabilities']??[])))),
                'priority'=>(int)$a->priority,'status'=>$a->isAvailable()?'healthy':($a->enabled?'degraded':'disabled'),
                'quota'=>['used'=>(float)$a->quota_used_seconds,'limit'=>$a->quota_limit_seconds===null?null:(float)$a->quota_limit_seconds],
                'latency_ms'=>$a->last_latency_ms,'reliability'=>(int)$a->reliability_score,'quality'=>(int)$a->quality_score,
                'credential_configured'=>filled($a->credentials), 'fallback'=>false,
            ];
        }
        foreach ($this->ai->capabilities() as $name=>$capabilities) {
            $configured = $name === 'gemini'
                ? filled(SiteSetting::read('gemini_api_key') ?: config('services.gemini.key'))
                : false;
            $items[] = [
                'id' => 'ai:'.$name,
                'name' => $name,
                'provider' => $name,
                'type' => 'ai',
                'model' => $name === 'gemini'
                    ? (string) SiteSetting::read('gemini_model', config('services.gemini.model', 'gemini-3.8-flash'))
                    : null,
                'capabilities' => array_values(array_unique(array_merge(['ai.text'], array_keys((array) $capabilities)))),
                'priority' => 0,
                'status' => $configured ? 'configured' : 'unavailable',
                'connection_verified' => false,
                'last_success_at' => null,
                'quota' => null,
                'latency_ms' => null,
                'reliability' => null,
                'quality' => null,
                'credential_configured' => $configured,
                'fallback' => false,
            ];
        }

        // Groq is configured and used by the global assistant/ASR path, but it
        // is not an AiProviderRegistry adapter. Expose it without claiming that
        // its credentials have passed a live connectivity test.
        $groqConfigured = filled(SiteSetting::read('voice_groq_key') ?: config('services.groq.key'));
        $items[] = [
            'id' => 'ai:groq',
            'name' => 'Groq',
            'provider' => 'groq',
            'type' => 'ai',
            'model' => (string) SiteSetting::read('assistant_groq_model', 'openai/gpt-oss-120b'),
            'capabilities' => ['ai.text', 'speech.stt'],
            'priority' => 0,
            'status' => $groqConfigured ? 'configured' : 'unavailable',
            'connection_verified' => false,
            'last_success_at' => null,
            'quota' => null,
            'latency_ms' => null,
            'reliability' => null,
            'quality' => null,
            'credential_configured' => $groqConfigured,
            'fallback' => false,
        ];

        return $items;
    }

    public function forCapability(string $capability, ?string $locale=null): mixed
    {
        if (in_array($capability,['speech.stt','voice'],true)) return $this->voice->best($locale ?: 'fa-IR');
        if (str_starts_with($capability,'ai.')) return $this->ai->forOperation(['capability'=>$capability]);
        throw new \InvalidArgumentException('provider_capability_not_supported');
    }
}
