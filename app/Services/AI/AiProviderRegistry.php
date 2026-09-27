<?php

namespace App\Services\AI;

use App\Services\AI\Providers\GeminiEditorProvider;
use App\Services\AI\Providers\GeminiProviderAdapter;
use InvalidArgumentException;

final class AiProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    public function __construct(GeminiEditorProvider $gemini)
    {
        $this->register(new GeminiProviderAdapter($gemini));
    }

    public function register(AiProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(string $name): AiProviderInterface
    {
        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException('ai_provider_not_registered');
        }

        return $this->providers[$name];
    }

    /** @param array<string, mixed> $operation */
    public function forOperation(array $operation, ?string $preferred = null): AiProviderInterface
    {
        if ($preferred !== null) {
            $provider = $this->get($preferred);
            if (! $provider->supports($operation)) {
                throw new InvalidArgumentException('ai_provider_operation_unsupported');
            }
            return $provider;
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($operation)) {
                return $provider;
            }
        }

        throw new InvalidArgumentException('ai_provider_unavailable');
    }

    /** @return array<string, array<string, mixed>> */
    public function capabilities(): array
    {
        $result = [];
        foreach ($this->providers as $name => $provider) {
            $result[$name] = $provider->capabilities();
        }

        return $result;
    }
}
