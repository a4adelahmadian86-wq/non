<?php

namespace Tests\Feature;

use App\Console\Commands\CheckStorageVolumes;
use App\Http\Controllers\AdminStorageController;
use App\Models\StorageVolume;
use App\Services\StorageConnectionService;
use App\Services\Storage\S3CompatibleAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class StorageFreeTierGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_manual_storage_cap_and_health_interval_without_initial_inventory(): void
    {
        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::type(StorageVolume::class), false, true)
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

    public function test_failed_volume_is_backed_off_after_a_probe_failure(): void
    {
        StorageVolume::create([
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
            'metadata' => [
                'health_check_interval_minutes' => 60,
                'health_check_failures' => 2,
                'health_check_next_at' => now()->addMinutes(30)->toIso8601String(),
            ],
        ]);

        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldNotReceive('verify');
        $this->app->instance(StorageConnectionService::class, $connections);

        $this->artisan('storage:check-volumes')
            ->expectsOutput('Healthy: 0; failed: 0; skipped: 1')
            ->assertExitCode(0);
    }

    public function test_unhealthy_volume_is_retried_after_backoff_and_resets_failure_state_on_success(): void
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
            'last_checked_at' => now()->subMinutes(90),
            'metadata' => [
                'health_check_interval_minutes' => 60,
                'health_check_failures' => 2,
                'health_check_next_at' => now()->subMinute()->toIso8601String(),
                'last_error' => 'previous failure',
            ],
        ]);

        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn ($candidate) => $candidate->is($volume)), false, true)
            ->andReturnUsing(function (StorageVolume $candidate): array {
                $candidate->forceFill([
                    'status' => 'healthy',
                    'last_checked_at' => now(),
                    'last_success_at' => now(),
                    'metadata' => array_merge((array) $candidate->metadata, ['inventory_attempted_at' => null]),
                ])->save();

                return ['read' => true, 'write' => true, 'delete' => true];
            });
        $this->app->instance(StorageConnectionService::class, $connections);

        $this->artisan('storage:check-volumes')
            ->expectsOutput('Healthy: 1; failed: 0; skipped: 0')
            ->assertExitCode(0);

        $metadata = (array) $volume->fresh()->metadata;
        $this->assertArrayNotHasKey('health_check_failures', $metadata);
        $this->assertArrayNotHasKey('health_check_next_at', $metadata);
        $this->assertArrayNotHasKey('last_error', $metadata);
    }

    public function test_unhealthy_volume_is_retried_even_inside_the_normal_interval_after_explicit_expiry(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Expired retry volume',
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
            ->with(Mockery::on(fn ($candidate) => $candidate->is($volume)), false, true)
            ->andReturnUsing(function (StorageVolume $candidate): array {
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

    public function test_failure_uses_exponential_delay_and_persists_next_attempt(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Failing volume',
            'driver' => 's3',
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'farast-private',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 4500000000,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'error',
            'is_active' => true,
            'metadata' => ['health_check_interval_minutes' => 60, 'health_check_failures' => 1],
        ]);

        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn ($candidate) => $candidate->is($volume)), false, true)
            ->andThrow(new \RuntimeException('temporary outage'));
        $this->app->instance(StorageConnectionService::class, $connections);

        $this->artisan('storage:check-volumes')->assertExitCode(1);

        $metadata = (array) $volume->fresh()->metadata;
        $this->assertSame(2, (int) $metadata['health_check_failures']);
        $this->assertTrue(now()->addMinutes(119)->lt(\Illuminate\Support\Carbon::parse($metadata['health_check_next_at'])));
        $this->assertTrue(now()->addMinutes(121)->gt(\Illuminate\Support\Carbon::parse($metadata['health_check_next_at'])));
    }

    public function test_scheduled_s3_verification_skips_probe_inventory_even_when_no_inventory_exists(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Inventory-protected volume',
            'driver' => 's3',
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'farast-private',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 4500000000,
            'used_bytes' => 1234,
            'object_count' => 7,
            'status' => 'unverified',
            'is_active' => true,
            'metadata' => [],
        ]);

        $adapter = Mockery::mock(S3CompatibleAdapter::class);
        $adapter->shouldReceive('probe')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($volume)))
            ->andReturn(['read' => true, 'write' => true, 'delete' => true]);
        $adapter->shouldNotReceive('inventory');

        $service = new StorageConnectionService($adapter);
        $result = $service->verify($volume, false, true);

        $this->assertSame(['read' => true, 'write' => true, 'delete' => true], $result);
        $this->assertSame('healthy', $volume->fresh()->status);
        $this->assertSame(1234, (int) $volume->fresh()->used_bytes);
        $this->assertSame(7, (int) $volume->fresh()->object_count);
        $this->assertNull(data_get($volume->fresh()->metadata, 'inventory_attempted_at'));
    }

    public function test_explicit_s3_verification_can_refresh_inventory(): void
    {
        $volume = StorageVolume::create([
            'drive_key' => 'iran_primary',
            'name' => 'Manual inventory volume',
            'driver' => 's3',
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'farast-private',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'capacity_bytes' => 4500000000,
            'used_bytes' => 0,
            'object_count' => 0,
            'status' => 'unverified',
            'is_active' => true,
            'metadata' => [],
        ]);

        $adapter = Mockery::mock(S3CompatibleAdapter::class);
        $adapter->shouldReceive('probe')->once()->andReturn(['read' => true, 'write' => true, 'delete' => true]);
        $adapter->shouldReceive('inventory')->once()->with(Mockery::on(fn ($candidate) => $candidate->is($volume)))
            ->andReturn(['used_bytes' => 9876, 'object_count' => 11]);

        $service = new StorageConnectionService($adapter);
        $service->verify($volume, true);

        $fresh = $volume->fresh();
        $this->assertSame(9876, (int) $fresh->used_bytes);
        $this->assertSame(11, (int) $fresh->object_count);
        $this->assertNotNull(data_get($fresh->metadata, 'inventory_attempted_at'));
    }


    public function test_iran_primary_defaults_to_daily_health_checks_when_interval_is_omitted(): void
    {
        $connections = Mockery::mock(StorageConnectionService::class);
        $connections->shouldReceive('verify')
            ->once()
            ->with(Mockery::type(StorageVolume::class), false, true)
            ->andReturnNull();

        $request = Request::create('/admin/storage', 'POST', [
            'drive_key' => 'iran_primary',
            'connection_json' => json_encode([
                'driver' => 's3',
                'endpoint' => 'https://s3.example.com',
                'bucket' => 'farast-private',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            ], JSON_THROW_ON_ERROR),
        ]);

        app(AdminStorageController::class)->store($request, $connections);

        $volume = StorageVolume::query()->sole();
        $this->assertSame(1440, (int) data_get($volume->metadata, 'health_check_interval_minutes'));
    }

}
