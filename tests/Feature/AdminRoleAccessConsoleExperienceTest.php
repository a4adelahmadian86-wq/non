<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleAccessConsoleExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_role_console_with_persian_role_labels_and_grouped_permissions(): void
    {
        app(AuthorizationService::class)->syncDefaults();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.access'));

        $response->assertOk()
            ->assertSee('مدیریت نقش‌ها و مجوزها')
            ->assertSee('مدیر سازمان')
            ->assertSee('سرپرست تیم')
            ->assertSee('کارمند')
            ->assertSee('اسناد و فایل‌ها')
            ->assertSee('هوش مصنوعی')
            ->assertSee('name="permissions[]"', false)
            ->assertSee('data-permission-search', false)
            ->assertSee('data-group-action="select"', false)
            ->assertSee(route('admin.access.roles.update', 'employee'), false);
    }

    public function test_master_admin_role_is_explicitly_locked_in_the_role_console(): void
    {
        app(AuthorizationService::class)->syncDefaults();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.access'))
            ->assertOk()
            ->assertSee('این نقش دسترسی سراسری دارد')
            ->assertSee('role-access-locked', false)
            ->assertDontSee('ذخیره مجوزهای مدیر اصلی');
    }

    public function test_non_admin_cannot_open_role_permission_console(): void
    {
        app(AuthorizationService::class)->syncDefaults();

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)
            ->get(route('admin.access'))
            ->assertForbidden();
    }
}
