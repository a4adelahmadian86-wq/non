<?php

namespace App\Services;

use Illuminate\Support\Str;

class FarastTraceContext
{
    private ?string $requestId = null;
    private ?string $correlationId = null;

    public function set(?string $requestId, ?string $correlationId): void
    {
        $this->requestId = $this->validIdentifier($requestId);
        $this->correlationId = $this->validIdentifier($correlationId)
            ?: $this->requestId
            ?: (string) Str::uuid();
    }

    public function current(): array
    {
        return [
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ];
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
    }

    private function validIdentifier(?string $value): ?string
    {
        if ($value === null || strlen($value) > 64) {
            return null;
        }

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $value) === 1
            ? $value
            : null;
    }
}
