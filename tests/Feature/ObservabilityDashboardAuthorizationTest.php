<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FarastObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservabilityDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile, string $role): User
    {
        return User::create([
            'name' => 'Observability '.$role,
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => $role,
        ]);
    }

    public function test_dashboard_requires_existing_global_admin_and_audit_section_authorization(): void
    {
        $snapshot = [
            'status' => 'healthy',
            'checked_at' => now()->toIso8601String(),
            'environment' => 'testing',
            'observability_enabled' => true,
            'components' => [],
        ];
        $service = \Mockery::mock(FarastObservabilityService::class);
        $service->shouldReceive('snapshot')->once()->andReturn($snapshot);
        app()->instance(FarastObservabilityService::class, $service);

        $this->get('/admin/observability')->assertRedirect('/login');

        $employee = $this->user('09150000981', User::ROLE_EMPLOYEE);
        $this->actingAs($employee)->get('/admin/observability')->assertForbidden();

        $admin = $this->user('09150000982', User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/admin/observability')->assertOk()
            ->assertSee('سلامت و مشاهده‌پذیری سامانه');
    }
}
