<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRuntimeHealthIndicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_sidebar_uses_measured_runtime_state_instead_of_static_green_status(): void
    {
        $admin = User::create([
            'name' => 'Runtime Health Admin',
            'email' => 'runtime-health-admin@example.test',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('id="system-health"', false)
            ->assertSee('وضعیت زیرساخت و اتصال‌های داخلی')
            ->assertSee('RUNTIME HEALTH')
            ->assertDontSee('کنترل‌های زنده فعال هستند');
    }
}
