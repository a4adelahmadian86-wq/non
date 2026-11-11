<?php

namespace Tests\Unit;

use App\Services\FarastTraceContext;
use PHPUnit\Framework\TestCase;

class FarastTraceContextTest extends TestCase
{
    public function test_it_preserves_trace_ids_until_explicit_cleanup(): void
    {
        $context = new FarastTraceContext();

        $context->set('request-123', 'correlation-456');

        self::assertSame([
            'request_id' => 'request-123',
            'correlation_id' => 'correlation-456',
        ], $context->current());

        $context->clear();

        self::assertSame([
            'request_id' => null,
            'correlation_id' => null,
        ], $context->current());
    }

    public function test_correlation_id_is_generated_when_only_request_id_is_missing(): void
    {
        $context = new FarastTraceContext();

        $context->set(null, null);

        self::assertNull($context->current()['request_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/i', $context->current()['correlation_id']);
    }
}
