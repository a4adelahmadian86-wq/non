<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AiProviderInterface;
use App\Services\AI\AiProviderResult;

final class GeminiProviderAdapter implements AiProviderInterface
{
    public function __construct(private GeminiEditorProvider $provider) {}

    public function name(): string
    {
        return $this->provider->name();
    }

    public function model(): ?string
    {
        return $this->provider->model();
    }

    public function capabilities(): array
    {
        return ['text' => true, 'structured_output' => true];
    }

    public function supports(array $operation): bool
    {
        return $this->provider->supports($operation);
    }

    public function execute(array $request): AiProviderResult
    {
        $raw = $this->provider->execute(
            $request['operation'],
            $request['payload'],
            $request['request_id'],
        );

        return new AiProviderResult(
            result: $raw['result'] ?? [],
            providerRequestId: $raw['provider_interaction_id'] ?? null,
            model: $this->model(),
            usage: $raw['usage'] ?? null,
            metadata: [
                'http_status' => $raw['http_status'] ?? null,
                'output_bytes' => $raw['output_bytes'] ?? 0,
                'prompt_hash' => $raw['prompt_hash'] ?? null,
            ],
        );
    }
}
