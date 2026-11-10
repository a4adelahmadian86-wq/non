<?php

namespace Tests\Feature;

use App\Models\FarastNotification;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_communication_center_aggregates_existing_ticket_and_notification_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        Ticket::create([
            'user_id' => $admin->id,
            'subject' => 'پیگیری سفارش آزمایشی',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
        ]);
        FarastNotification::create([
            'user_id' => $admin->id,
            'type' => 'ticket_created_staff',
            'title' => 'تیکت تازه',
            'body' => 'یک درخواست پشتیبانی ثبت شد.',
        ]);

        $this->actingAs($admin)->get(route('admin.communications.index'))
            ->assertOk()
            ->assertSee('مرکز ارتباطات فراست')
            ->assertSee('پیگیری سفارش آزمایشی')
            ->assertSee('تیکت تازه')
            ->assertSee('میز تیکت‌ها')
            ->assertSee('پیام‌رسان داخلی');
    }

    public function test_customer_can_use_shared_center_without_internal_messenger_access(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        FarastNotification::create([
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
            'title' => 'پاسخ پشتیبانی مشتری',
            'body' => 'پاسخ جدید برای درخواست شما ثبت شد.',
        ]);

        $this->actingAs($customer)->get(route('platform.communications'))
            ->assertOk()
            ->assertSee('مرکز ارتباطات فراست')
            ->assertSee('پاسخ پشتیبانی مشتری')
            ->assertDontSee('پیام‌رسان داخلی')
            ->assertDontSee('گفت‌وگوهای داخلی');
    }

    public function test_workspace_navigation_exposes_shared_center_but_hides_internal_messenger_from_customers(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);
        $groups = app(\App\Services\DashboardNavigationService::class)->forUser($customer);
        $items = collect($groups)->flatMap(fn ($group) => $group['items']);

        $this->assertTrue($items->contains(fn ($item) => $item['href'] === route('platform.communications')));
        $this->assertFalse($items->contains(fn ($item) => $item['href'] === route('messenger.index')));
    }

    public function test_non_admin_cannot_open_staff_communication_center(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_blocked' => false]);

        $this->actingAs($customer)->get(route('admin.communications.index'))->assertForbidden();
    }
}
