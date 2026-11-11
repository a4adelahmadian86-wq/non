<?php

namespace App\Services;

use Illuminate\Support\Str;

class FarastTraceContext
{
    private ?string $requestId = null;
    private ?string $correlationId = null;

    public function set(?string $requestId, ?string $correlationId): void
    {
        $this->requestId = $requestId;
        $this->correlationId = $correlationId ?: $requestId ?: (string) Str::uuid();
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
}
