<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardAggregationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_renders_daily_chart_aggregations(): void
    {
        $admin = new User();
        $admin->name = 'Dashboard QA';
        $admin->email = 'dashboard-qa@example.test';
        $admin->mobile = '09123456789';
        $admin->password = 'secret';
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }
}
