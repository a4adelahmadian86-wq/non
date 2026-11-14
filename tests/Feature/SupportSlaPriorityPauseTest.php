<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use Mockery;
use App\Services\SupportSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSlaPriorityPauseTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_change_does_not_rewrite_a_completed_first_response_deadline(): void
    {
        config([
            'support.business_hours_only' => false,
            'support.sla_first_response_minutes.normal' => 240,
            'support.sla_first_response_minutes.high' => 60,
            'support.sla_resolution_minutes.normal' => 1440,
            'support.sla_resolution_minutes.high' => 480,
        ]);

        $createdAt = now()->startOfMinute();
        $ticket = Ticket::create([
            'subject' => 'First response already completed',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $createdAt->copy()->addMinutes(240),
            'sla_resolve_due_at' => $createdAt->copy()->addMinutes(1440),
            'first_response_at' => $createdAt->copy()->addMinutes(30),
        ]);

        $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();
        $originalFirstDueAt = $ticket->sla_first_due_at->copy();

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );
        $service->updateMeta($ticket, ['priority' => 'high']);

        $updated = $ticket->fresh();
        $this->assertTrue($updated->sla_first_due_at->equalTo($originalFirstDueAt));
        $this->assertTrue($updated->sla_resolve_due_at->equalTo($createdAt->copy()->addMinutes(480)));
    }

    public function test_priority_change_cannot_rewrite_closed_ticket_sla_history(): void
    {
        config([
            'support.business_hours_only' => false,
            'support.sla_first_response_minutes.high' => 60,
            'support.sla_resolution_minutes.high' => 480,
        ]);

        $createdAt = now()->startOfMinute()->subDays(3);
        $ticket = Ticket::create([
            'subject' => 'Closed SLA history',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $createdAt->copy()->addMinutes(240),
            'sla_resolve_due_at' => $createdAt->copy()->addMinutes(1440),
            'first_response_at' => $createdAt->copy()->addMinutes(300),
            'resolved_at' => $createdAt->copy()->addMinutes(1800),
            'closed_at' => $createdAt->copy()->addMinutes(1800),
        ]);

        $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();
        $originalFirstDueAt = $ticket->sla_first_due_at->copy();
        $originalResolveDueAt = $ticket->sla_resolve_due_at->copy();

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );
        $service->updateMeta($ticket, ['priority' => 'high']);

        $updated = $ticket->fresh();
        $this->assertSame('high', $updated->priority);
        $this->assertTrue($updated->sla_first_due_at->equalTo($originalFirstDueAt));
        $this->assertTrue($updated->sla_resolve_due_at->equalTo($originalResolveDueAt));
    }

    public function test_priority_change_during_a_long_pending_period_uses_the_pause_instant(): void
    {
        config([
            'support.business_hours_only' => false,
            'support.sla_first_response_minutes.high' => 30,
            'support.sla_resolution_minutes.high' => 60,
        ]);

        $createdAt = now()->startOfMinute()->subDays(3);
        $pausedAt = $createdAt->copy()->addMinutes(30);
        $this->travelTo($createdAt->copy()->addDays(3));

        $ticket = Ticket::create([
            'subject' => 'Priority changed after prolonged customer wait',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'sla_paused_at' => $pausedAt,
            'sla_resolve_remaining_minutes' => 1410,
        ]);
        $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );
        $service->updateMeta($ticket, ['priority' => 'high']);

        $updated = $ticket->fresh();
        $this->assertSame('pending', $updated->status);
        $this->assertTrue($updated->sla_paused_at->equalTo($pausedAt));
        $this->assertSame(30, (int) $updated->sla_resolve_remaining_minutes);
        $this->assertTrue($updated->sla_resolve_due_at->equalTo($createdAt->copy()->addMinutes(60)));
    }

    public function test_priority_recalculation_updates_remaining_budget_without_unpausing_ticket(): void
    {
        config([
            'support.business_hours_only' => false,
            'support.sla_first_response_minutes.high' => 30,
            'support.sla_resolution_minutes.high' => 60,
        ]);

        $now = now()->startOfMinute();
        $this->travelTo($now);

        $ticket = Ticket::create([
            'subject' => 'Priority changed while pending',
            'status' => 'pending',
            'priority' => 'high',
            'department' => 'support',
            'sla_paused_at' => $now->copy()->subMinutes(10),
            'sla_resolve_remaining_minutes' => 5,
        ]);
        $ticket->forceFill(['created_at' => $now->copy()->subMinutes(30)])->saveQuietly();

        app(SupportSlaService::class)->applyTargets($ticket->fresh());

        $updated = $ticket->fresh();
        $this->assertNotNull($updated->sla_paused_at);
        $this->assertSame(40, (int) $updated->sla_resolve_remaining_minutes);
        $this->assertTrue($updated->sla_resolve_due_at->equalTo($now->copy()->addMinutes(30)));
    }
}
