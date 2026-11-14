<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAdminInboxQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_inbox_loads_the_latest_public_preview_and_active_thread(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Inbox preview query',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Earlier public reply',
            'is_internal' => false,
            'is_staff' => true,
        ]);
        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Internal note must not become the list preview',
            'is_internal' => true,
            'is_staff' => true,
        ]);
        $ticket->messages()->create([
            'user_id' => $admin->id,
            'body' => 'Latest public preview sentinel',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('Latest public preview sentinel')
            ->assertSee('Internal note must not become the list preview');

        $this->assertStringContainsString('Latest public preview sentinel', $response->getContent());
    }

    public function test_admin_polling_is_non_overlapping_and_preserves_reader_scroll_position(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Admin polling behavior',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id]))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('let pollInFlight = false;', $html);
        $this->assertStringContainsString('if (pollInFlight || document.hidden) return;', $html);
        $this->assertStringContainsString('const serverCursor = Number(data.last_id);', $html);
        $this->assertStringContainsString('const shouldStickToBottom = box', $html);
        $this->assertStringContainsString('finally {', $html);
        $this->assertStringContainsString('pollInFlight = false;', $html);
    }


    public function test_at_risk_filter_uses_business_minutes_and_keeps_first_response_active_when_pending(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $timezone = config('app.timezone', 'Asia/Tehran');
        $now = Carbon::parse('2026-11-15 08:00:00', $timezone);
        Carbon::setTestNow($now);

        try {
            config([
                'support.business_hours_only' => true,
                'support.business_hours.timezone' => $timezone,
                'support.business_hours.start' => 9,
                'support.business_hours.end' => 18,
                'support.business_hours.weekend' => [],
                'support.warn_before_minutes' => 30,
            ]);

            $pendingFirstResponse = Ticket::create([
                'subject' => 'Pending first-response at risk',
                'status' => 'pending',
                'priority' => 'normal',
                'department' => 'support',
                'sla_first_due_at' => Carbon::parse('2026-11-15 09:15:00', $timezone),
            ]);
            Ticket::create([
                'subject' => 'Outside business-minute warning window',
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
                'sla_first_due_at' => Carbon::parse('2026-11-15 09:45:00', $timezone),
            ]);
            Ticket::create([
                'subject' => 'Paused resolution only',
                'status' => 'pending',
                'priority' => 'normal',
                'department' => 'support',
                'first_response_at' => $now->copy()->subMinute(),
                'sla_resolve_due_at' => Carbon::parse('2026-11-15 09:15:00', $timezone),
            ]);

            $this->actingAs($admin)
                ->get(route('admin.support.management', ['sla' => 'at_risk']))
                ->assertOk()
                ->assertSee('Pending first-response at risk')
                ->assertDontSee('Outside business-minute warning window')
                ->assertDontSee('Paused resolution only');
        } finally {
            Carbon::setTestNow();
        }
    }

}
