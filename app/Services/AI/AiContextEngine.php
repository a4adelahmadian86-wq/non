<?php

namespace App\Services\AI;

use Illuminate\Validation\ValidationException;

class AiContextEngine
{
    public function build(array $operation, string $text, array $context = []): array
    {
        if (trim($text) === '') {
            throw ValidationException::withMessages(['text' => 'متن برای پردازش خالی است.']);
        }

        if (mb_strlen($text) > (int) $operation['max_chars']) {
            $message = $operation['scope'] === 'document'
                ? 'سند برای این عملیات بزرگ‌تر از حد پردازش تک‌مرحله‌ای است و بدون راهبرد قطعه‌بندی ارسال نمی‌شود.'
                : 'متن انتخاب‌شده از سقف مجاز این عملیات بزرگ‌تر است.';
            throw ValidationException::withMessages(['text' => $message]);
        }

        foreach ($operation['required_context'] ?? [] as $key) {
            if (trim((string) ($context[$key] ?? '')) === '') {
                throw ValidationException::withMessages([$key => 'این مقدار برای عملیات انتخاب‌شده الزامی است.']);
            }
        }

        $safe = [];
        foreach (['sentence', 'before_cursor', 'after_cursor', 'target_language', 'tone', 'instruction'] as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }
            $value = (string) $context[$key];
            $safe[$key] = match ($key) {
                'sentence' => mb_substr($value, 0, 4000),
                'before_cursor', 'after_cursor' => mb_substr($value, 0, 12000),
                'instruction' => mb_substr($value, 0, 1000),
                default => mb_substr($value, 0, 120),
            };
        }

        if (is_array($context['product_reference'] ?? null)) {
            $reference = $context['product_reference'];
            $safe['product_reference'] = [
                'id' => (int) ($reference['id'] ?? 0),
                'type' => mb_substr((string) ($reference['type'] ?? ''), 0, 40),
                'title' => mb_substr((string) ($reference['title'] ?? ''), 0, 300),
                'short_description' => mb_substr((string) ($reference['short_description'] ?? ''), 0, 1200),
                'description' => mb_substr((string) ($reference['description'] ?? ''), 0, 4000),
                'category' => mb_substr((string) ($reference['category'] ?? ''), 0, 160),
                'tags' => array_slice(array_map(static fn ($tag) => mb_substr((string) $tag, 0, 100), (array) ($reference['tags'] ?? [])), 0, 20),
            ];
        }

        if (is_array($context['document_knowledge'] ?? null)) {
            $knowledge = $context['document_knowledge'];
            $safe['document_knowledge'] = [
                'knowledge_status' => (string) ($knowledge['knowledge_status'] ?? 'unavailable'),
                'scope' => (string) ($knowledge['scope'] ?? 'unknown'),
                'source_count' => (int) ($knowledge['source_count'] ?? 0),
                'sources' => array_slice((array) ($knowledge['sources'] ?? []), 0, 8),
                'chunks' => array_slice((array) ($knowledge['chunks'] ?? []), 0, 24),
                'facts' => array_slice((array) ($knowledge['facts'] ?? []), 0, 50),
                'warnings' => array_slice((array) ($knowledge['warnings'] ?? []), 0, 10),
            ];
        }

        $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'text' => $text,
            'context' => $safe,
            'scope' => $operation['scope'],
            'transmitted_bytes' => strlen($text) + strlen(is_string($encoded) ? $encoded : ''),
        ];
    }

    public function buildLayered(array $operation, string $text, array $layers = []): array
    {
        $context = [];
        foreach (['system', 'tool', 'user', 'project', 'document', 'selection', 'task', 'approved_knowledge'] as $layer) {
            if (isset($layers[$layer]) && is_array($layers[$layer])) {
                $context = array_merge($context, $layers[$layer]);
            }
        }

        return $this->build($operation, $text, $context);
    }
}
