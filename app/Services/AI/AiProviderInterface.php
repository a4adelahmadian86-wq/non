<?php

namespace App\Services\AI;

interface AiProviderInterface
{
    public function name(): string;

    public function model(): ?string;

    /** @return array<string, mixed> */
    public function capabilities(): array;

    /** @param array<string, mixed> $operation */
    public function supports(array $operation): bool;

    /** @param array<string, mixed> $request */
    public function execute(array $request): AiProviderResult;
}
