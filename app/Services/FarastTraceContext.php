<?php

namespace App\Services;

/**
 * Request/job-scoped trace context shared by audit writers and operational services.
 *
 * The container binding is a singleton for the lifetime of a PHP worker; callers
 * must clear it in finally blocks so Octane and queue workers cannot leak context.
 */
class FarastTraceContext
{
    private ?string $requestId = null;

    private ?string $correlationId = null;

    private bool $active = false;

    public function set(?string $requestId, ?string $correlationId): void
    {
        $this->requestId = $this->normalize($requestId);
        $this->correlationId = $this->normalize($correlationId);
        $this->active = true;
    }

    /**
     * @return array{request_id: ?string, correlation_id: ?string}
     */
    public function current(): array
    {
        return [
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
        $this->active = false;
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 64
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $value) === 1
                ? $value
                : null;
    }
}
