<?php

namespace Tests\Feature;

use App\Models\FarastNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile): User
    {
        return User::create([
            'name' => 'کاربر اعلان',
            'mobile' => $mobile,
            'password' => 'secret',
            'role' => 'user',
        ]);
    }

    public function test_notification_categories_are_consistent_for_security_and_marketing_events(): void
    {
        $this->assertSame('security', (new FarastNotification(['type' => 'security_login']))->category());
        $this->assertSame('marketing', (new FarastNotification(['type' => 'announcement_campaign']))->category());
        $this->assertSame('announcement', (new FarastNotification(['type' => 'announcement_warning']))->category());
        $this->assertSame('system', (new FarastNotification(['type' => 'workflow']))->category());
    }

    public function test_notification_center_filters_and_search_share_the_same_user_scoped_ledger(): void
    {
        $user = $this->user('09150000101');
        $other = $this->user('09150000102');
        $service = app(NotificationService::class);

        $service->push($user->id, 'security_login', 'ورود جدید به حساب', 'ورود از دستگاه تازه');
        $service->push($user->id, 'announcement_campaign', 'پیشنهاد ویژه فراست', 'اطلاع‌رسانی کمپین');
        $service->push($user->id, 'announcement_info', 'به‌روزرسانی سرویس', 'اطلاعیه رسمی');
        $service->push($user->id, 'workflow', 'گردش‌کار اجرا شد', 'عملیات حساب شما تکمیل شد');
        $service->push($other->id, 'security_login', 'اعلان خصوصی کاربر دیگر', 'این اعلان نباید نمایش داده شود');

        $this->actingAs($user)->get(route('platform.notifications', ['filter' => 'security']))
            ->assertOk()->assertSee('ورود جدید به حساب')->assertSee('nf-cat-security')
            ->assertDontSee('nf-cat-marketing')->assertDontSee('اعلان خصوصی کاربر دیگر');

        $this->actingAs($user)->get(route('platform.notifications', ['filter' => 'marketing']))
            ->assertOk()->assertSee('پیشنهاد ویژه فراست')->assertSee('nf-cat-marketing')->assertDontSee('nf-cat-announcement');

        $this->actingAs($user)->get(route('platform.notifications', ['filter' => 'announcement']))
            ->assertOk()->assertSee('به‌روزرسانی سرویس')->assertSee('nf-cat-announcement')->assertDontSee('nf-cat-marketing');

        $this->actingAs($user)->get(route('platform.notifications', ['filter' => 'system', 'q' => 'گردش‌کار']))
            ->assertOk()->assertSee('گردش‌کار اجرا شد')->assertSee('nf-cat-system')->assertDontSee('nf-cat-security');
    }

    public function test_user_can_archive_and_restore_only_their_own_notification(): void
    {
        $owner = $this->user('09150000103');
        $other = $this->user('09150000104');
        $notification = app(NotificationService::class)->push($owner->id, 'ticket_reply', 'پاسخ پشتیبانی', 'پاسخ جدید');

        $this->actingAs($other)->post(route('platform.notifications.archive', $notification->id))->assertNotFound();
        $this->assertDatabaseHas('farast_notifications', ['id' => $notification->id, 'archived_at' => null]);

        $this->actingAs($owner)->post(route('platform.notifications.archive', $notification->id))->assertRedirect();
        $this->assertDatabaseMissing('farast_notifications', ['id' => $notification->id, 'archived_at' => null]);
        $this->assertSame(0, app(NotificationService::class)->unreadCount($owner->id));

        $this->actingAs($owner)->get(route('platform.notifications', ['filter' => 'archived']))
            ->assertOk()->assertSee('پاسخ پشتیبانی')->assertSee('بازگردانی');

        $this->actingAs($owner)->post(route('platform.notifications.restore', $notification->id))->assertRedirect();
        $this->assertDatabaseHas('farast_notifications', ['id' => $notification->id, 'archived_at' => null]);
        $this->assertSame(0, app(NotificationService::class)->unreadCount($owner->id));
    }

    public function test_latest_and_unread_count_ignore_archived_notifications_and_are_user_scoped(): void
    {
        $user = $this->user('09150000105');
        $other = $this->user('09150000106');
        $service = app(NotificationService::class);
        $active = $service->push($user->id, 'ticket_reply', 'اعلان فعال');
        $archived = $service->push($user->id, 'ticket_reply', 'اعلان بایگانی');
        $service->archive($user->id, $archived->id);
        $service->push($other->id, 'ticket_reply', 'اعلان کاربر دیگر');

        $this->assertSame(1, $service->unreadCount($user->id));
        $this->assertSame([$active->id], $service->latest($user->id, 10)->pluck('id')->all());

        $this->actingAs($user)->getJson(route('notifications.latest'))
            ->assertOk()->assertJsonPath('count', 1)->assertJsonMissing(['title' => 'اعلان بایگانی'])->assertJsonMissing(['title' => 'اعلان کاربر دیگر']);
    }

    public function test_notifications_center_uses_the_workspace_shell_and_bell_is_available_on_public_pages(): void
    {
        $user = $this->user('09150000107');
        $this->actingAs($user)->get(route('platform.notifications'))->assertOk()->assertSee('farast-dashboard-nav');
        $this->get(route('home'))->assertOk()->assertSee('nf-bell-menu');
    }
}
