<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketAuditTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_manager_sees_ticket_audit_history_with_human_readable_actor_and_sla_context(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Ticket audit timeline subject',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        app(AuditEventService::class)->record(
            'support.ticket.status_transitioned',
            $admin->id,
            null,
            'ticket',
            $ticket->id,
            [
                'from_status' => 'open',
                'to_status' => 'pending',
                'sla_before' => ['resolution_paused_at' => null],
                'sla_after' => ['resolution_paused_at' => now()->toIso8601String()],
            ],
            'support'
        );

        $response = $this->actingAs($admin)->get(
            route('admin.support.management', ['ticket' => $ticket->id])
        );

        $response->assertOk()
            ->assertSee('تاریخچه تغییرات ممیزی‌شده')
            ->assertSee('تغییر وضعیت: از باز به در انتظار مشتری')
            ->assertSee($admin->name)
            ->assertSee('ساعت SLA حل متوقف شد')
            ->assertDontSee('resolution_paused_at');
    }
}
