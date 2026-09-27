<?php

namespace App\Services;

use App\Models\VoiceCorrection;
use Illuminate\Support\Str;

class VoiceCorrectionService
{
    public function apply(string $text, string $locale = 'fa-IR', ?string $provider = null, ?int $userId = null): string
    {
        $text = trim($text);
        if ($text === '') return $text;

        $query = VoiceCorrection::query()
            ->where('locale', $locale)
            ->where('confidence', '>=', 0.90)
            ->where(function ($q) use ($userId) {
                $q->where('global', true)->orWhere(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->whereNotNull('user_id');
                });
            });

        $corrections = $query->orderByDesc('confidence')->orderByDesc('occurrences')->limit(500)->get();

        foreach ($corrections as $correction) {
            $wrong = trim($correction->wrong_text);
            $right = trim($correction->correct_text);
            if ($wrong === '' || $right === '' || $wrong === $right) continue;
            $pattern = '/(?<!\p{L})' . preg_quote($wrong, '/') . '(?!\p{L})/u';
            $text = preg_replace($pattern, $right, $text) ?? $text;
        }

        return trim($text);
    }

    public function learn(?int $userId, string $locale, ?string $provider, string $wrong, string $correct, ?string $context = null): VoiceCorrection
    {
        $wrong = trim($wrong);
        $correct = trim($correct);
        $hash = $context ? hash('sha256', Str::lower(trim($context))) : null;

        $row = VoiceCorrection::query()
            ->where('user_id', $userId)
            ->where('locale', $locale)
            ->where('wrong_text', $wrong)
            ->where('correct_text', $correct)
            ->first();

        if (!$row) {
            $row = VoiceCorrection::create([
                'user_id' => $userId,
                'locale' => $locale,
                'provider' => $provider,
                'wrong_text' => $wrong,
                'correct_text' => $correct,
                'context_hash' => $hash,
                'occurrences' => 1,
                'confirmations' => 1,
                'confidence' => 0.15,
                'global' => false,
                'last_seen_at' => now(),
            ]);
        } else {
            $row->increment('occurrences');
            $row->increment('confirmations');
            $row->refresh();
            $confidence = min(0.99, 1 - exp(-$row->confirmations / 8));
            $row->update(['confidence' => round($confidence, 4), 'last_seen_at' => now()]);
        }

        return $row->fresh();
    }
}
