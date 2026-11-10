<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationInformationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_global_admin_sees_a_grouped_navigation_with_operationally_related_sections(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.index'));

        $this->assertSame(6, substr_count($response->getContent(), 'class="farast-admin-nav-group"'));

        $response->assertOk()
            ->assertSee('aria-label="دسته‌بندی‌های مدیریت"', false)
            ->assertSee('class="farast-admin-nav-group"', false)
            ->assertSee('عملیات')
            ->assertSee('محصولات و مشتریان')
            ->assertSee('مالی و اشتراک')
            ->assertSee('پیکربندی پلتفرم')
            ->assertSee('حاکمیت و تحلیل')
            ->assertSee('ارتباطات و محتوا')
            ->assertSee(route('admin.store.create'))
            ->assertSee(route('admin.storage.index'))
            ->assertSee(route('admin.crm.marketing.index'))
            ->assertSee(route('admin.communications.index'))
            ->assertSee(route('admin.store-coupons.index'))
            ->assertSee(route('admin.crm.customers.index'))
            ->assertSee(route('admin.finance'))
            ->assertSee(route('admin.access'))
            ->assertSee(route('admin.support.management'));
    }

    public function test_navigation_marks_the_current_admin_section_as_active(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.finance'));

        $response->assertOk()
            ->assertSee('مالی و اشتراک')
            ->assertSee('aria-current="page"', false);
    }
}
