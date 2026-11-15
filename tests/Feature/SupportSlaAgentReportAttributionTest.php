<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSlaAgentReportAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_report_uses_persisted_first_response_when_legacy_public_message_is_missing(): void
    {
        config(['support.business_hours_only' => false]);
        $createdAt = now()->startOfMinute();
        $this->travelTo($createdAt);
        $assignedAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $ticket = Ticket::create([
            'subject' => 'Legacy response timestamp',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $assignedAgent->id,
            'sla_first_due_at' => $createdAt->copy()->addMinutes(30),
            'first_response_at' => $createdAt->copy()->addMinutes(20),
        ]);

        $report = app(SupportSlaService::class)->agentReport(
            $createdAt->copy()->subMinute(),
            $createdAt->copy()->addMinute(),
        );
        $assignedRow = collect($report['agents'])->firstWhere('id', $assignedAgent->id);

        $this->assertNotNull($assignedRow);
        $this->assertSame(1, $assignedRow['assigned']);
        $this->assertSame(1, $assignedRow['first_response_total']);
        $this->assertSame(1, $assignedRow['first_response_met']);
        $this->assertSame(20, $assignedRow['avg_first_response_minutes']);
        $this->assertSame(20, $assignedRow['median_first_response_minutes']);
        $this->assertSame(100.0, $assignedRow['sla_rate']);
        $this->assertSame(1, $report['totals']['first_response_total']);
        $this->assertSame(1, $report['totals']['first_response_met']);
        $this->assertSame(20, $report['totals']['avg_first_response_minutes']);
    }
    public function test_agent_report_attributes_first_response_and_replies_to_the_actual_staff_author(): void
    {
        config(['support.business_hours_only' => false]);

        $createdAt = now()->startOfMinute();
        $this->travelTo($createdAt);
        $assignedAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $responder = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $internalAuthor = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $ticket = Ticket::create([
            'subject' => 'Response attribution',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $assignedAgent->id,
            'sla_first_due_at' => $createdAt->copy()->addMinutes(30),
        ]);

        $this->travel(20)->minutes();
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $responder->id,
            'body' => 'First public response',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $this->travel(10)->minutes();
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $responder->id,
            'body' => 'Second public response',
            'is_internal' => false,
            'is_staff' => true,
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $internalAuthor->id,
            'body' => 'Private internal note',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $report = app(SupportSlaService::class)->agentReport(
            $createdAt->copy()->subMinute(),
            now()->copy()->addMinute()
        );

        $assignedRow = collect($report['agents'])->firstWhere('id', $assignedAgent->id);
        $responderRow = collect($report['agents'])->firstWhere('id', $responder->id);

        $this->assertNotNull($assignedRow);
        $this->assertSame(1, $assignedRow['assigned']);
        $this->assertSame(0, $assignedRow['replies']);
        $this->assertSame(0, $assignedRow['first_response_total']);

        $this->assertNotNull($responderRow);
        $this->assertSame(0, $responderRow['assigned']);
        $this->assertSame(2, $responderRow['replies']);
        $this->assertSame(1, $responderRow['first_response_total']);
        $this->assertSame(1, $responderRow['first_response_met']);
        $this->assertSame(20, $responderRow['avg_first_response_minutes']);

        $this->assertNull(collect($report['agents'])->firstWhere('id', $internalAuthor->id));
        $this->assertSame(1, $report['totals']['first_response_total']);
        $this->assertSame(1, $report['totals']['first_response_met']);
    }
}
