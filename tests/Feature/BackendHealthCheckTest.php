<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackendHealthCheckTest extends TestCase
{
    public function test_local_backend_health_check_validates_database_and_private_storage(): void
    {
        Storage::fake('private');

        $this->artisan('farast:health-check')
            ->expectsOutputToContain('database: ready')
            ->expectsOutputToContain('private_storage: ready')
            ->expectsOutputToContain('support_submission_schema: ready')
            ->assertExitCode(0);
    }

    public function test_backend_health_check_fails_when_support_idempotency_schema_is_missing(): void
    {
        config([
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        Storage::fake('private');

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('tickets', 'submission_scope')
            ->andReturn(false);

        $this->artisan('farast:health-check')
            ->expectsOutputToContain('support_submission_schema: failed')
            ->assertExitCode(1);
    }

    public function test_production_health_check_requires_a_backup_encryption_key(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.key' => 'base64:health-check-test-key',
            'storage_management.backup.encryption_key' => '',
        ]);

        Storage::fake('private');

        $this->artisan('farast:health-check')
            ->expectsOutputToContain('Backend checks: 7 passed, 1 failed.')
            ->assertExitCode(1);
    }

    public function test_production_health_check_rejects_debug_mode(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.key' => 'base64:health-check-test-key']);

        Storage::fake('private');

        $this->artisan('farast:health-check')
            ->expectsOutputToContain('production_config: failed')
            ->assertExitCode(1);
    }
}
