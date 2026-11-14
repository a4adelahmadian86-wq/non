<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use Tests\Concerns\RefreshDatabaseWithoutOuterTransactionForMySql;
use Mockery;
use Tests\TestCase;

class SupportTicketAuditLedgerTest extends TestCase
{
    use RefreshDatabaseWithoutOuterTransactionForMySql;

    public function test_status_metadata_and_assignment_changes_append_sanitized_audit_events(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Audit ledger support ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('statusChanged')->once();
        $notifier->shouldReceive('assigned')->once();
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        $service = app(SupportTicketService::class);
        $service->changeStatus($ticket, 'pending', $actor, false);
        $service->updateMeta($ticket, ['priority' => 'high', 'department' => 'billing'], $actor);
        $service->assign($ticket, $agent, $actor);

        $events = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->orderBy('id')
            ->get();

        $this->assertSame([
            'support.ticket.status_transitioned',
            'support.ticket.metadata_changed',
            'support.ticket.assignment_changed',
        ], $events->pluck('event_type')->all());

        $this->assertSame($actor->id, $events[0]->user_id);
        $this->assertSame($actor->id, $events[1]->user_id);
        $this->assertSame($actor->id, $events[2]->user_id);
        $this->assertSame('support', $events[0]->source);
        $this->assertNotNull($events[0]->created_at);

        $status = $events[0]->payload_meta;
        $this->assertSame('open', $status['from_status']);
        $this->assertSame('pending', $status['to_status']);
        $this->assertNotNull($status['sla_after']['resolution_paused_at']);

        $metadata = $events[1]->payload_meta;
        $this->assertSame('normal', $metadata['before']['priority']);
        $this->assertSame('high', $metadata['after']['priority']);
        $this->assertSame('support', $metadata['before']['department']);
        $this->assertSame('billing', $metadata['after']['department']);
        $this->assertNotNull($metadata['sla_after']['resolution_due_at']);

        $assignment = $events[2]->payload_meta;
        $this->assertNull($assignment['from_assigned_to']);
        $this->assertSame($agent->id, $assignment['to_assigned_to']);

        $encoded = json_encode($events->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Audit ledger support ticket', $encoded);
        $this->assertStringNotContainsString('message body', $encoded);
    }

    public function test_ticket_creation_appends_a_sanitized_creation_event_to_the_audit_ledger(): void
    {
        $customer = User::factory()->create(['email' => 'audit-customer@example.test']);

        $ticket = app(SupportTicketService::class)->createTicket($customer, [
            'subject' => 'Private ticket subject',
            'body' => 'Private initial message content',
            'source' => 'dashboard',
            'department' => 'billing',
            'priority' => 'high',
            'topic_area' => 'services',
            'related_service' => 'ocr',
        ]);

        $event = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->where('event_type', 'support.ticket.created')
            ->firstOrFail();

        $this->assertSame($customer->id, $event->user_id);
        $this->assertSame('support', $event->source);
        $this->assertSame('open', $event->payload_meta['status']);
        $this->assertSame('dashboard', $event->payload_meta['source']);
        $this->assertSame('billing', $event->payload_meta['department']);
        $this->assertSame('high', $event->payload_meta['priority']);
        $this->assertSame('services', $event->payload_meta['topic_area']);
        $this->assertSame('ocr', $event->payload_meta['related_service']);
        $this->assertSame('customer', $event->payload_meta['initiator_type']);

        $encoded = json_encode($event->payload_meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private ticket subject', $encoded);
        $this->assertStringNotContainsString('Private initial message content', $encoded);
        $this->assertStringNotContainsString('audit-customer@example.test', $encoded);
    }

    public function test_guest_ticket_creation_audit_does_not_store_guest_contact_details(): void
    {
        $ticket = app(SupportTicketService::class)->createTicket(null, [
            'subject' => 'Guest private subject',
            'body' => 'Guest private message',
            'guest_name' => 'Private Guest Name',
            'guest_email' => 'guest-private@example.test',
            'source' => 'widget',
            'department' => 'support',
            'priority' => 'normal',
        ]);

        $event = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->where('event_type', 'support.ticket.created')
            ->firstOrFail();

        $this->assertNull($event->user_id);
        $this->assertSame('guest', $event->payload_meta['initiator_type']);
        $this->assertSame('widget', $event->payload_meta['source']);

        $encoded = json_encode($event->payload_meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Private Guest Name', $encoded);
        $this->assertStringNotContainsString('guest-private@example.test', $encoded);
        $this->assertStringNotContainsString('Guest private subject', $encoded);
        $this->assertStringNotContainsString('Guest private message', $encoded);
    }

    public function test_ticket_creation_rolls_back_when_audit_append_fails(): void
    {
        $customer = User::factory()->create();
        $audit = Mockery::mock(\App\Services\AuditEventService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit ledger unavailable'));
        $this->app->instance(\App\Services\AuditEventService::class, $audit);

        try {
            app(SupportTicketService::class)->createTicket($customer, [
                'subject' => 'Must not persist',
                'body' => 'Must not persist',
                'priority' => 'normal',
                'department' => 'support',
            ]);
            $this->fail('Ticket creation must roll back when its audit event cannot be appended.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit ledger unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_messages', 0);
        $this->assertDatabaseCount('farast_workflow_events', 0);
        $this->assertDatabaseCount('farast_audit_events', 0);
    }

    public function test_noop_status_change_does_not_append_an_audit_event(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'No-op audit test',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        app(SupportTicketService::class)->changeStatus($ticket, 'open', $actor, false);

        $this->assertDatabaseMissing('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticket->id,
        ]);
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_audit_ledger_failure_rolls_back_ticket_status_transition(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Audit rollback test',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $audit = Mockery::mock(\App\Services\AuditEventService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit ledger unavailable'));
        $this->app->instance(\App\Services\AuditEventService::class, $audit);

        try {
            app(SupportTicketService::class)->changeStatus($ticket, 'pending', $actor, false);
            $this->fail('A failed audit append must abort the ticket transition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit ledger unavailable', $exception->getMessage());
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseMissing('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticket->id,
        ]);
    }

    public function test_customer_reply_status_transition_is_audited_without_message_content(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Customer reply audit',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('customerReplied')->once();
        $this->app->instance(SupportNotificationService::class, $notifier);

        app(SupportTicketService::class)->addMessage(
            $ticket,
            $customer,
            'محتوای محرمانه پیام مشتری',
            ['is_staff' => false]
        );

        $event = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->where('event_type', 'support.ticket.status_transitioned')
            ->firstOrFail();

        $this->assertSame($customer->id, $event->user_id);
        $this->assertSame('open', $event->payload_meta['from_status']);
        $this->assertSame('user_replied', $event->payload_meta['to_status']);
        $this->assertStringNotContainsString(
            'محتوای محرمانه پیام مشتری',
            json_encode($event->payload_meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function test_reassigning_ticket_to_the_same_agent_does_not_append_duplicate_history(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => null,
            'subject' => 'No-op assignment audit',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $agent->id,
        ]);

        $auditCount = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->count();
        $messageCount = TicketMessage::query()->where('ticket_id', $ticket->id)->count();

        app(SupportTicketService::class)->assign($ticket, $agent, $admin);

        $this->assertSame($auditCount, FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->count());
        $this->assertSame($messageCount, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }


    public function test_reapplying_identical_ticket_metadata_does_not_append_duplicate_history(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => null,
            'subject' => 'No-op metadata audit',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $before = FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->count();

        app(SupportTicketService::class)->updateMeta($ticket, [
            'priority' => 'normal',
            'department' => 'support',
        ], $admin);

        $this->assertSame($before, FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->count());
    }


    public function test_invalid_status_values_are_rejected_without_persisting_ticket_changes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'user_id' => null,
            'subject' => 'Invalid status validation',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $service = app(SupportTicketService::class);

        try {
            $service->changeStatus($ticket, 'not-a-valid-status', $admin);
            $this->fail('Unknown status values must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('وضعیت نامعتبر است.', $exception->getMessage());
        }

        try {
            $service->addMessage($ticket, $admin, 'This message must roll back', [
                'is_staff' => true,
                'status' => 'not-a-valid-status',
            ]);
            $this->fail('A reply with an invalid status must be rejected atomically.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, FarastAuditEvent::query()
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->count());
    }

}
