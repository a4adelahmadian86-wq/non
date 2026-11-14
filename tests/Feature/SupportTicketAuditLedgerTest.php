<?php

namespace Tests\Feature;

use App\Models\FarastAuditEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupportTicketAuditLedgerTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertDatabaseCount('farast_audit_events', 0);
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

        $audit = Mockery::mock(\\App\\Services\\AuditEventService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \\RuntimeException('audit ledger unavailable'));
        $this->app->instance(\\App\\Services\\AuditEventService::class, $audit);

        try {
            app(SupportTicketService::class)->changeStatus($ticket, 'pending', $actor, false);
            $this->fail('A failed audit append must abort the ticket transition.');
        } catch (\\RuntimeException $exception) {
            $this->assertSame('audit ledger unavailable', $exception->getMessage());
        }

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseCount('farast_audit_events', 0);
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
}
