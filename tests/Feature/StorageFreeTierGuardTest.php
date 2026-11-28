<?php

namespace Tests\Feature;

use App\Console\Commands\CheckStorageVolumes;
use App\Http\Controllers\AdminStorageController;
use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class StorageFreeTierGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_manual_storage_cap_and_health_interval(): void
    {
        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::type(StorageVolume::class), true)
            ->andReturnNull();

        $request = Request::create('/admin/storage', 'POST', [
            'drive_key' => 'iran_primary',
            'connection_json' => json_encode([
                'driver' => 's3',
                'region' => 'region-1',
                'endpoint' => 'https://s3.example.com',
                'bucket' => 'farast-private',
                'capacity_bytes' => 4500000000,
                'file_limit' => 10000,
                'max_file_bytes' => 104857600,
                'health_check_interval_minutes' => 60,
                'credentials' => [
                    'key' => 'test-access-key',
                    'secret' => 'test-secret-key',
                    'use_path_style_endpoint' => true,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        app(AdminStorageController::class)->store($request, $connections);

        $volume = StorageVolume::query()->sole();
        $this->assertSame(4500000000, (int) $volume->capacity_bytes);
        $this->assertSame(10000, (int) $volume->file_limit);
        $this->assertSame(104857600, (int) $volume->max_file_bytes);
        $this->assertSame(60, (int) data_get($volume->metadata, 'health_check_interval_minutes'));
    }

    public function test_healthy_volume_is_not_probed_before_its_configured_interval(): void
    {
        StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Arvan free-tier volume',
            'driver' => 's3',
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'farast-private',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 4500000000,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'healthy',
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(30),
            'metadata' => ['health_check_interval_minutes' => 60],
        ]);

        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldNotReceive('verify');
        $this->app->instance(StorageConnectionService::class, $connections);

        $this->artisan('storage:check-volumes')
            ->expectsOutput('Healthy: 0; failed: 0; skipped: 1')
            ->assertExitCode(0);
    }

    public function test_unhealthy_volume_is_retried_even_inside_the_normal_interval(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Temporarily unavailable volume',
            'driver' => 's3',
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'farast-private',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 4500000000,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'error',
            'is_active' => true,
            'last_checked_at' => now()->subMinutes(10),
            'metadata' => ['health_check_interval_minutes' => 60],
        ]);

        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn ($candidate) => $candidate->is($volume)))
            ->andReturnUsing(function (StorageVolume $candidate): array {
                // Mirror the state transition performed by StorageConnectionService::verify().
                $candidate->forceFill([
                    'status' => 'healthy',
                    'last_checked_at' => now(),
                    'last_success_at' => now(),
                ])->save();

                return ['read' => true, 'write' => true, 'delete' => true];
            });
        $this->app->instance(StorageConnectionService::class, $connections);

        $this->artisan('storage:check-volumes')
            ->expectsOutput('Healthy: 1; failed: 0; skipped: 0')
            ->assertExitCode(0);
    }
}
