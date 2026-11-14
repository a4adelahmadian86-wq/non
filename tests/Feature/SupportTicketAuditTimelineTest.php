<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupportTicketAuditTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_manager_sees_real_ticket_audit_history_with_actor_and_sla_context(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Ticket audit timeline subject',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('statusChanged')->once();
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        // Exercise the real canonical writer and then read its ledger entry
        // through the same authorized admin Inbox path used in production.
        app(SupportTicketService::class)->changeStatus($ticket, 'pending', $admin, false);

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
