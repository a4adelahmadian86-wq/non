<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\FarastNotification;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\EmailService;
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

    public function test_communications_filter_returns_only_internal_messenger_notifications(): void
    {
        $user = $this->user('09150000120');
        $service = app(NotificationService::class);
        $service->push($user->id, 'message_received', 'پیام داخلی', 'پیام جدید در گفت‌وگو');
        $service->push($user->id, 'ticket_reply', 'پاسخ تیکت', 'پاسخ پشتیبانی');
        $service->push($user->id, 'order_paid', 'پرداخت موفق', 'سفارش ثبت شد');

        $items = $service->paginateForUser($user->id, 'communications', 20);

        $this->assertSame(['پیام داخلی'], $items->getCollection()->pluck('title')->all());
        $this->assertSame(1, $service->countsByCategory($user->id)['communications']);
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

    public function test_duplicate_delivery_keys_return_the_existing_notification(): void
    {
        $user = $this->user('09150000111');
        $service = app(NotificationService::class);

        $first = $service->push($user->id, 'ticket_reply', 'پاسخ جدید', 'پاسخ ثبت شد.', [], false, 'support:ticket:45:event:ticket_reply:user:'.$user->id);
        $second = $service->push($user->id, 'ticket_reply', 'پاسخ جدید', 'پاسخ ثبت شد.', [], false, 'support:ticket:45:event:ticket_reply:user:'.$user->id);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('farast_notifications', 1);
    }

    public function test_dedupe_keys_cannot_be_reused_across_users(): void
    {
        $owner = $this->user('09150000112');
        $other = $this->user('09150000113');
        $service = app(NotificationService::class);

        $service->push($owner->id, 'ticket_reply', 'پاسخ خصوصی', 'متن خصوصی', [], false, 'support:ticket:46:event:ticket_reply:user:'.$owner->id);
        $conflict = $service->push($other->id, 'ticket_reply', 'نباید تحویل شود', 'نباید به کاربر دیگر برسد', [], false, 'support:ticket:46:event:ticket_reply:user:'.$owner->id);

        $this->assertNull($conflict);
        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', ['user_id' => $owner->id, 'title' => 'پاسخ خصوصی']);
        $this->assertDatabaseMissing('farast_notifications', ['user_id' => $other->id]);
    }

    public function test_failed_announcement_delivery_is_not_marked_as_broadcasted(): void
    {
        $user = $this->user('09150000108');
        $announcement = Announcement::create([
            'title' => 'اختلال آزمایشی ارسال',
            'body' => 'این اعلان باید در صف تلاش مجدد باقی بماند.',
            'type' => 'warning',
            'audience' => 'all',
            'is_active' => true,
            'notify_in_app' => true,
            'notify_email' => false,
        ]);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('push')->once()->with(
            $user->id,
            'announcement_warning',
            'اختلال آزمایشی ارسال',
            'این اعلان باید در صف تلاش مجدد باقی بماند.',
            \Mockery::type('array'),
            false,
            \Mockery::type('string')
        )->andReturn(null);

        $email = \Mockery::mock(EmailService::class);
        $service = new AnnouncementService($notifications, $email);

        $this->assertSame(0, $service->broadcast($announcement));
        $this->assertNull($announcement->fresh()->notified_at);
    }

    public function test_successful_announcement_delivery_is_counted_and_marked_complete(): void
    {
        $user = $this->user('09150000109');
        $announcement = Announcement::create([
            'title' => 'انتشار موفق',
            'body' => 'این اعلان با موفقیت ثبت می‌شود.',
            'type' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'notify_in_app' => true,
            'notify_email' => false,
        ]);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('push')->once()->andReturn(new FarastNotification());
        $email = \Mockery::mock(EmailService::class);
        $service = new AnnouncementService($notifications, $email);

        $this->assertSame(1, $service->broadcast($announcement));
        $this->assertNotNull($announcement->fresh()->notified_at);
    }


    public function test_retried_announcement_broadcast_does_not_duplicate_in_app_notifications(): void
    {
        $user = $this->user('09150000110');
        $announcement = Announcement::create([
            'title' => 'ارسال قابل تکرار',
            'body' => 'اعلان باید در تلاش مجدد تکراری نشود.',
            'type' => 'info',
            'audience' => 'all',
            'is_active' => true,
            'notify_in_app' => true,
            'notify_email' => false,
        ]);

        $service = app(AnnouncementService::class);
        $this->assertSame(1, $service->broadcast($announcement));
        $this->assertSame(1, $service->broadcast($announcement));

        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $user->id,
            'type' => 'announcement_info',
            'dedupe_key' => 'announcement:'.$announcement->id.':revision:1:user:'.$user->id,
        ]);

        // An explicit admin rebroadcast is a new delivery, unlike an automatic retry.
        $announcement->forceFill(['broadcast_revision' => 2])->saveQuietly();
        $this->assertSame(1, $service->broadcast($announcement->fresh()));

        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $user->id,
            'type' => 'announcement_info',
            'dedupe_key' => 'announcement:'.$announcement->id.':revision:2:user:'.$user->id,
        ]);
    }

    public function test_notifications_center_uses_the_workspace_shell_and_bell_is_available_on_public_pages(): void
    {
        $user = $this->user('09150000107');
        $this->actingAs($user)->get(route('platform.notifications'))->assertOk()->assertSee('farast-dashboard-nav');
        $this->get(route('home'))->assertOk()->assertSee('nf-bell-menu');
    }
}
