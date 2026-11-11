<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\SupportNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportNotificationEventIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_distinct_ticket_replies_create_distinct_notifications_but_retries_are_idempotent(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'پیگیری سفارش',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $firstMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'پاسخ اول',
            'is_internal' => false,
            'is_staff' => true,
        ]);
        $secondMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => 'پاسخ دوم',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->staffReplied($ticket, $firstMessage->body, false, (int) $firstMessage->id);
        $service->staffReplied($ticket, $firstMessage->body, false, (int) $firstMessage->id);

        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $user->id,
            'type' => 'ticket_reply',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_reply:identity:message:'.$firstMessage->id.':user:'.$user->id,
        ]);

        $service->staffReplied($ticket, $secondMessage->body, false, (int) $secondMessage->id);

        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $user->id,
            'type' => 'ticket_reply',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_reply:identity:message:'.$secondMessage->id.':user:'.$user->id,
        ]);
    }

    public function test_customer_replies_use_the_message_identity_for_staff_notifications(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $staff->id,
            'subject' => 'پیگیری پرداخت',
            'status' => 'user_replied',
            'priority' => 'normal',
            'department' => 'billing',
        ]);
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'رسید پرداخت را ارسال کردم',
            'is_internal' => false,
            'is_staff' => false,
        ]);

        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->customerReplied($ticket, $message->body, (int) $message->id);
        $service->customerReplied($ticket, $message->body, (int) $message->id);

        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $staff->id,
            'type' => 'ticket_customer_reply',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_customer_reply:identity:message:'.$message->id.':user:'.$staff->id,
        ]);
    }
}
