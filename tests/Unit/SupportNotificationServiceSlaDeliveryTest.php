<?php

namespace Tests\Unit;

use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\SupportNotificationService;
use RuntimeException;
use Tests\TestCase;

class SupportNotificationServiceSlaDeliveryTest extends TestCase
{
    public function test_sla_breach_is_not_reported_as_delivered_without_any_eligible_recipient_or_channel(): void
    {
        $service = $this->serviceWithoutRecipients();
        $ticket = $this->ticket();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no-eligible-recipient');

        $service->slaBreached($ticket, 'first_response', 'no-recipient-breach');
    }

    public function test_sla_warning_is_not_reported_as_delivered_without_any_eligible_recipient_or_channel(): void
    {
        $service = $this->serviceWithoutRecipients();
        $ticket = $this->ticket();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no-eligible-recipient');

        $service->slaWarning($ticket, 'first_response', 5, 'no-recipient-warning');
    }

    private function serviceWithoutRecipients(): SupportNotificationService
    {
        $email = \Mockery::mock(EmailService::class);
        $sms = \Mockery::mock(SmsService::class);
        $notifications = \Mockery::mock(NotificationService::class);

        return new class($email, $sms, $notifications) extends SupportNotificationService {
            protected function staffTargets(Ticket $ticket)
            {
                return collect();
            }

            protected function smsEnabled(string $event): bool
            {
                return false;
            }
        };
    }

    private function ticket(): Ticket
    {
        $ticket = new Ticket();
        $ticket->subject = 'SLA test without an eligible recipient';
        $ticket->priority = 'normal';
        $ticket->ticket_code = 'SLA-NO-RECIPIENT';
        $ticket->id = 4242;
        $ticket->setRelation('assignee', null);
        $ticket->setRelation('user', null);

        return $ticket;
    }
}
