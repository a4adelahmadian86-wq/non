<?php

namespace Tests\Feature;

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
            ->assertExitCode(0);
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
