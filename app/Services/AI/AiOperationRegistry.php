<?php

namespace App\Services\AI;

use InvalidArgumentException;

class AiOperationRegistry
{
    private const OPERATIONS = [
        'selection.format_suggest' => ['aliases' => ['selection'], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'low', 'result' => 'format_suggestions'],
        'selection.spellcheck' => ['aliases' => ['word'], 'scope' => 'word', 'max_chars' => 500, 'cost' => 'low', 'result' => 'spelling'],
        'selection.punctuation' => ['aliases' => ['punctuation'], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'low', 'result' => 'text'],
        'selection.proofread' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'low', 'result' => 'proofread'],
        'selection.rewrite' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'medium', 'result' => 'text'],
        'selection.paraphrase' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'medium', 'result' => 'text'],
        'selection.tone' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'medium', 'result' => 'text', 'required_context' => ['tone']],
        'selection.shorten' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'medium', 'result' => 'text'],
        'selection.expand' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 20000, 'cost' => 'medium', 'result' => 'text'],
        'selection.summarize' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'medium', 'result' => 'text'],
        'selection.explain' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'medium', 'result' => 'text'],
        'selection.translate' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'medium', 'result' => 'text', 'required_context' => ['target_language']],
        'text.generate' => ['aliases' => [], 'scope' => 'prompt', 'max_chars' => 12000, 'cost' => 'medium', 'result' => 'text'],
        'text.continue' => ['aliases' => [], 'scope' => 'cursor', 'max_chars' => 30000, 'cost' => 'medium', 'result' => 'text'],
        'text.title' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'low', 'result' => 'titles'],
        'text.outline' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'medium', 'result' => 'outline'],
        'text.keywords' => ['aliases' => [], 'scope' => 'selection', 'max_chars' => 30000, 'cost' => 'low', 'result' => 'keywords'],
        'document.summarize' => ['aliases' => [], 'scope' => 'document', 'max_chars' => 60000, 'cost' => 'high', 'result' => 'text'],
        'document.improve' => ['aliases' => [], 'scope' => 'document', 'max_chars' => 60000, 'cost' => 'high', 'result' => 'document_review'],
        'document.analyze' => ['aliases' => [], 'scope' => 'document', 'max_chars' => 60000, 'cost' => 'high', 'result' => 'document_review'],
    ];

    public function resolve(string $operation): array
    {
        $operation = trim($operation);
        foreach (self::OPERATIONS as $name => $definition) {
            if ($operation === $name || in_array($operation, $definition['aliases'], true)) {
                return $definition + ['name' => $name, 'requested_name' => $operation, 'processing_modes' => ['external'], 'provider_capability' => 'text'];
            }
        }
        throw new InvalidArgumentException('عملیات هوش مصنوعی پشتیبانی نمی‌شود.');
    }

    public function publicDefinitions(): array
    {
        return collect(self::OPERATIONS)->map(fn (array $definition, string $name) => [
            'name' => $name,
            'scope' => $definition['scope'],
            'cost' => $definition['cost'],
            'processing_modes' => ['external'],
        ])->values()->all();
    }
}
