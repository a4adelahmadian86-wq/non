<?php

namespace App\Services\AI;

final class AiProviderResult
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public readonly array $result,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $model = null,
        public readonly ?array $usage = null,
        public readonly array $metadata = [],
    ) {}
}
