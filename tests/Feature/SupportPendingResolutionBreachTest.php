<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupportPendingResolutionBreachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.business_hours_only' => false]);
    }

    private function notifier(): SupportNotificationService
    {
        return Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing();
    }

    public function test_entering_pending_after_resolution_deadline_preserves_and_reports_the_breach(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Already overdue before waiting for customer',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => now()->subMinutes(15),
        ]);

        $notifier = $this->notifier();
        $notifier->shouldReceive('slaBreached')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'resolution');

        $service = new SupportTicketService($notifier, new SupportSlaService());
        $updated = $service->changeStatus($ticket, 'pending', null, false);

        $this->assertNotNull($updated->sla_resolve_breached_at);
        $this->assertSame(0, (int) $updated->sla_resolve_remaining_minutes);
        $this->assertTrue(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());

        $status = app(SupportSlaService::class)->resolutionStatus($updated);
        $this->assertSame('paused', $status['state']);
        $this->assertTrue($status['breached']);
        $this->assertSame('danger', $status['color']);
    }

    public function test_staff_reply_transition_to_pending_also_records_a_preexisting_resolution_breach(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Staff asks customer for more information',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => now()->subMinutes(5),
        ]);

        $notifier = $this->notifier();
        $notifier->shouldReceive('slaBreached')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'resolution');

        $service = new SupportTicketService($notifier, new SupportSlaService());
        $service->addMessage($ticket, $staff, 'لطفاً اطلاعات تکمیلی را ارسال کنید.', [
            'is_staff' => true,
            'status' => 'pending',
        ]);

        $updated = $ticket->fresh();
        $this->assertSame('pending', $updated->status);
        $this->assertNotNull($updated->sla_resolve_breached_at);
        $this->assertTrue(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
    }

    public function test_priority_change_during_pending_can_reveal_a_breach_that_existed_at_pause_time(): void
    {
        config(['support.sla_resolution_minutes.high' => 60]);

        $createdAt = now()->subHours(2);
        $pausedAt = $createdAt->copy()->addMinutes(90);
        $ticket = Ticket::create([
            'subject' => 'Priority change reveals pre-pause breach',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'sla_paused_at' => $pausedAt,
            'sla_resolve_remaining_minutes' => 120,
        ]);
        $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();

        $notifier = $this->notifier();
        $notifier->shouldReceive('slaBreached')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'resolution');

        $service = new SupportTicketService($notifier, new SupportSlaService());
        $updated = $service->updateMeta($ticket, ['priority' => 'high']);

        $this->assertNotNull($updated->sla_resolve_breached_at);
        $this->assertSame($pausedAt->format('Y-m-d H:i:s'), $updated->sla_resolve_breached_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, (int) $updated->sla_resolve_remaining_minutes);
        $this->assertTrue(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
    }

    public function test_pending_ticket_that_was_not_overdue_is_not_marked_as_breached(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Within resolution SLA',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => now()->addMinutes(45),
        ]);

        $service = new SupportTicketService($this->notifier(), new SupportSlaService());
        $updated = $service->changeStatus($ticket, 'pending', null, false);

        $this->assertNull($updated->sla_resolve_breached_at);
        $this->assertFalse(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
        $this->assertFalse(app(SupportSlaService::class)->resolutionStatus($updated)['breached']);
    }
}
