<?php

namespace Tests\Feature;

use App\Jobs\DeliverFarastEmail;
use App\Models\EmailLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailServiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_ticket_reply_event_is_queued_once_but_a_new_reply_is_delivered_separately(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'ایمیل پاسخ تیکت',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $email = app(EmailService::class);
        $email->sendTicketReply($user, $ticket, 'پاسخ اول', 'message:101');
        $email->sendTicketReply($user, $ticket, 'پاسخ اول', 'message:101');

        $this->assertDatabaseCount('email_logs', 1);
        $this->assertDatabaseHas('email_logs', [
            'type' => 'ticket_reply',
            'user_id' => $user->id,
            'idempotency_key' => 'ticket-reply:'.$ticket->id.':message:101:user:'.$user->id,
        ]);
        Queue::assertPushed(DeliverFarastEmail::class, 1);

        $email->sendTicketReply($user, $ticket, 'پاسخ دوم', 'message:102');

        $this->assertDatabaseCount('email_logs', 2);
        $this->assertDatabaseHas('email_logs', [
            'type' => 'ticket_reply',
            'user_id' => $user->id,
            'idempotency_key' => 'ticket-reply:'.$ticket->id.':message:102:user:'.$user->id,
        ]);
        Queue::assertPushed(DeliverFarastEmail::class, 2);
    }

    public function test_a_failed_email_log_can_be_claimed_once_for_retry(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $user = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'بازیابی ارسال ایمیل',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $key = 'ticket-reply:'.$ticket->id.':message:retry:user:'.$user->id;

        EmailLog::create([
            'type' => 'ticket_reply',
            'to_email' => $user->email,
            'user_id' => $user->id,
            'subject' => 'پاسخ تیکت',
            'status' => 'failed',
            'attempts' => 3,
            'error' => 'temporary dispatch failure',
            'idempotency_key' => $key,
        ]);

        app(EmailService::class)->sendTicketReply($user, $ticket, 'پاسخ پس از خطا', 'message:retry');

        $this->assertDatabaseCount('email_logs', 1);
        $this->assertDatabaseHas('email_logs', [
            'idempotency_key' => $key,
            'status' => 'queued',
            'error' => null,
        ]);
        Queue::assertPushed(DeliverFarastEmail::class, 1);
    }

}
