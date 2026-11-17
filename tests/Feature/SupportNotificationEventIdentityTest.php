<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
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

    public function test_distinct_ticket_closures_are_not_suppressed_by_an_earlier_closure(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'بستن و بازگشایی تیکت',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->statusChanged($ticket, 'open', 'closed', 'transition:close-1');
        $service->statusChanged($ticket, 'open', 'closed', 'transition:close-1');
        $this->assertDatabaseCount('farast_notifications', 1);

        $service->statusChanged($ticket, 'open', 'closed', 'transition:close-2');

        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $user->id,
            'type' => 'ticket_closed',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_closed:identity:transition:close-2:user:'.$user->id,
        ]);
    }

    public function test_distinct_assignment_events_notify_the_same_agent_once_per_transition(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $staff->id,
            'subject' => 'ارجاع مجدد تیکت',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->assigned($ticket, $staff, 'assignment:1');
        $service->assigned($ticket, $staff, 'assignment:1');
        $this->assertDatabaseCount('farast_notifications', 1);

        $service->assigned($ticket, $staff, 'assignment:2');

        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $staff->id,
            'type' => 'ticket_assigned',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_assigned:identity:assignment:2:user:'.$staff->id,
        ]);
    }


    public function test_ticket_service_uses_persisted_message_ids_for_reply_deduplication(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $staff->id,
            'subject' => 'پاسخ‌های متوالی',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->instance(EmailService::class, \Mockery::mock(EmailService::class)->shouldIgnoreMissing());
        $this->instance(SmsService::class, \Mockery::mock(SmsService::class)->shouldIgnoreMissing());

        $service = app(SupportTicketService::class);
        $first = $service->addMessage($ticket, $staff, 'پاسخ اول', ['is_staff' => true]);
        $second = $service->addMessage($ticket, $staff, 'پاسخ دوم', ['is_staff' => true]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('farast_notifications', 2);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_reply:identity:message:'.$first->id.':user:'.$customer->id,
        ]);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_reply:identity:message:'.$second->id.':user:'.$customer->id,
        ]);
    }

    public function test_former_assignee_removal_notice_is_idempotent(): void
    {
        $customer = User::factory()->create();
        $formerAgent = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'تغییر مسئول تیکت',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->assignmentRemoved($ticket, $formerAgent, 'message:assignment-77');
        $service->assignmentRemoved($ticket, $formerAgent, 'message:assignment-77');

        $this->assertDatabaseCount('farast_notifications', 1);
        $this->assertDatabaseHas('farast_notifications', [
            'user_id' => $formerAgent->id,
            'type' => 'ticket_assignment_removed',
            'dedupe_key' => 'support:ticket:'.$ticket->id.':event:ticket_assignment_removed:identity:message:assignment-77:user:'.$formerAgent->id,
        ]);
    }

    public function test_blocked_former_assignee_receives_no_assignment_notice(): void
    {
        $customer = User::factory()->create();
        $formerAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_blocked' => true]);
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Restricted assignment handoff',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            app(NotificationService::class),
        );

        $service->assignmentRemoved($ticket, $formerAgent, 'message:assignment-88');

        $this->assertDatabaseCount('farast_notifications', 0);
    }


    public function test_in_app_delivery_failure_is_propagated_to_the_support_delivery_boundary(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Notification failure propagation',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('push')
            ->once()
            ->withArgs(fn (...$arguments): bool => (int) $arguments[0] === (int) $user->id
                && $arguments[1] === 'ticket_reply'
                && $arguments[5] === true)
            ->andThrow(new \RuntimeException('notification store unavailable'));

        $service = new SupportNotificationService(
            \Mockery::mock(EmailService::class)->shouldIgnoreMissing(),
            \Mockery::mock(SmsService::class)->shouldIgnoreMissing(),
            $notifications,
        );

        $this->expectException(\RuntimeException::class);
        $service->staffReplied($ticket, 'پاسخ آزمایشی', false, 987654);
    }

}
