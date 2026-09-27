<?php

namespace App\Services\AI;

use Illuminate\Validation\ValidationException;

class AiContextEngine
{
    public function build(array $operation, string $text, array $context = []): array
    {
        if (trim($text) === '') throw ValidationException::withMessages(['text' => 'متن برای پردازش خالی است.']);
        if (mb_strlen($text) > (int) $operation['max_chars']) {
            $message = $operation['scope'] === 'document' ? 'سند برای این عملیات بزرگ‌تر از حد پردازش تک‌مرحله‌ای است و بدون راهبرد قطعه‌بندی ارسال نمی‌شود.' : 'متن انتخاب‌شده از سقف مجاز این عملیات بزرگ‌تر است.';
            throw ValidationException::withMessages(['text' => $message]);
        }
        foreach ($operation['required_context'] ?? [] as $key) {
            if (trim((string) ($context[$key] ?? '')) === '') throw ValidationException::withMessages([$key => 'این مقدار برای عملیات انتخاب‌شده الزامی است.']);
        }
        $safeContext = [];
        foreach (['sentence', 'before_cursor', 'after_cursor', 'target_language', 'tone', 'instruction'] as $key) {
            if (! array_key_exists($key, $context)) continue;
            $value = (string) $context[$key];
            $safeContext[$key] = match ($key) {
                'sentence' => mb_substr($value, 0, 4000),
                'before_cursor', 'after_cursor' => mb_substr($value, 0, 12000),
                'instruction' => mb_substr($value, 0, 1000),
                default => mb_substr($value, 0, 120),
            };
        }
        return ['text' => $text, 'context' => $safeContext, 'scope' => $operation['scope'], 'transmitted_bytes' => strlen($text) + array_sum(array_map('strlen', $safeContext))];
    }
}
