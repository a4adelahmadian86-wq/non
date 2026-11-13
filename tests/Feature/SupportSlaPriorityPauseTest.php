<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\SupportSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSlaPriorityPauseTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame(30, (int) $updated->sla_resolve_remaining_minutes);
        $this->assertTrue($updated->sla_resolve_due_at->equalTo($now->copy()->addMinutes(30)));
    }
}
