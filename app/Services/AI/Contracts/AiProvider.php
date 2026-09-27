<?php

namespace App\Services\AI\Contracts;

interface AiProvider
{
    public function name(): string;
    public function model(): string;
    public function supports(array $operation): bool;
    public function execute(array $operation, array $payload, string $requestId): array;
}
