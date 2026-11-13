<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupportSlaPauseResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolution_sla_excludes_time_spent_waiting_for_customer(): void
    {
        config(['support.business_hours_only' => false]);
        $startedAt = now()->startOfMinute();
        $this->travelTo($startedAt);

        $ticket = Ticket::create([
            'subject' => 'SLA pause',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => $startedAt->copy()->addMinutes(120),
        ]);

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );

        $this->travel(30)->minutes();
        $service->changeStatus($ticket, 'pending');

        $paused = $ticket->fresh();
        $this->assertSame(90, (int) $paused->sla_resolve_remaining_minutes);
        $this->assertNotNull($paused->sla_paused_at);

        $this->travel(120)->minutes();
        $resumedAt = now();
        $service->changeStatus($paused, 'answered');

        $resumed = $ticket->fresh();
        $this->assertNull($resumed->sla_paused_at);
        $this->assertNull($resumed->sla_resolve_remaining_minutes);
        $this->assertTrue($resumed->sla_resolve_due_at->equalTo($resumedAt->copy()->addMinutes(90)));
    }

    public function test_customer_reply_resumes_resolution_sla_from_pending_state(): void
    {
        config(['support.business_hours_only' => false]);
        $startedAt = now()->startOfMinute();
        $this->travelTo($startedAt);

        $ticket = Ticket::create([
            'subject' => 'Customer resumed ticket',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => $startedAt->copy()->addMinutes(10),
            'sla_paused_at' => $startedAt->copy()->subMinutes(20),
            'sla_resolve_remaining_minutes' => 45,
        ]);

        $customer = \App\Models\User::factory()->create();
        $ticket->update(['user_id' => $customer->id]);

        $service = new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );

        $service->addMessage($ticket, $customer, 'اطلاعات تکمیلی', ['is_staff' => false, 'notify' => false]);

        $resumed = $ticket->fresh();
        $this->assertSame('user_replied', $resumed->status);
        $this->assertNull($resumed->sla_paused_at);
        $this->assertNull($resumed->sla_resolve_remaining_minutes);
        $this->assertTrue($resumed->sla_resolve_due_at->equalTo(now()->addMinutes(45)));
    }
}
