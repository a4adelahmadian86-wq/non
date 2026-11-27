<?php

namespace Tests\\Feature;

use App\\Services\\ProductSupervisorClient;
use Tests\\TestCase;

class RetryIncompleteProductDocumentsTest extends TestCase
{
    public function test_retry_command_exits_safely_when_product_supervisor_is_not_configured(): void
    {
        $this->mock(ProductSupervisorClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->once()->andReturnFalse();
        });

        $this->artisan('farast:retry-incomplete-product-documents')
            ->expectsOutput('Product Supervisor is not configured; incomplete documents remain queued for a later check.')
            ->assertExitCode(0);
    }
}
