<?php

namespace App\Services;

use App\Models\VoiceProviderAccount;

class VoiceProviderRouter
{
    public function __construct(private VoiceQuotaManager $quota) {}

    public function candidates(string $locale, array $excludeAccountIds = []): array
    {
        $accounts = VoiceProviderAccount::query()
            ->where('enabled', true)
            ->where('healthy', true)
            ->when($excludeAccountIds !== [], fn ($q) => $q->whereNotIn('id', $excludeAccountIds))
            ->get()
            ->filter(fn (VoiceProviderAccount $account) => $this->supportsLocale($account, $locale))
            ->filter(fn (VoiceProviderAccount $account) => $account->isAvailable())
            ->filter(fn (VoiceProviderAccount $account) => $this->quota->canStart($account, 1))
            ->values();

        return $accounts->sort(function (VoiceProviderAccount $a, VoiceProviderAccount $b) {
            $class = $this->classRank($a) <=> $this->classRank($b);
            if ($class !== 0) return $class;
            $quality = ((int) $b->quality_score) <=> ((int) $a->quality_score);
            if ($quality !== 0) return $quality;
            $capacity = ($this->capacitySeconds($b) ?? PHP_INT_MAX) <=> ($this->capacitySeconds($a) ?? PHP_INT_MAX);
            if ($capacity !== 0) return $capacity;
            $reliability = ((int) $b->reliability_score) <=> ((int) $a->reliability_score);
            if ($reliability !== 0) return $reliability;
            $latency = ($a->last_latency_ms ?? PHP_INT_MAX) <=> ($b->last_latency_ms ?? PHP_INT_MAX);
            if ($latency !== 0) return $latency;
            return ((int) $a->priority) <=> ((int) $b->priority);
        })->all();
    }

    public function best(string $locale, array $excludeAccountIds = []): ?VoiceProviderAccount
    {
        return $this->candidates($locale, $excludeAccountIds)[0] ?? null;
    }

    public function classRank(VoiceProviderAccount $account): int
    {
        return match ($account->billing_mode) {
            'trial' => $this->trialRank($account),
            'monthly_free' => 20,
            'paid' => 30,
            'emergency' => 40,
            default => 50,
        };
    }

    private function trialRank(VoiceProviderAccount $account): int
    {
        if ($account->credit_expires_at && $account->credit_expires_at->isPast()) return 19;
        return 10;
    }

    private function capacitySeconds(VoiceProviderAccount $account): ?int
    {
        $available = $this->quota->availableSeconds($account);
        return $available === null ? null : (int) floor($available);
    }

    private function supportsLocale(VoiceProviderAccount $account, string $locale): bool
    {
        $locales = $account->capabilities['locales'] ?? null;
        return !is_array($locales) || $locales === [] || in_array($locale, $locales, true);
    }
}
