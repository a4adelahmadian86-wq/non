<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AdminConsoleService;
use App\Services\SupportTicketService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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

    public function test_admin_inbox_filters_by_existing_ticket_source_and_topic_area(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Ticket::create([
            'subject' => 'Dashboard origin filter sentinel',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'source' => 'dashboard',
            'topic_area' => 'services',
        ]);
        Ticket::create([
            'subject' => 'Widget origin filter sentinel',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'source' => 'widget',
            'topic_area' => 'store',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.support.management', ['source' => 'widget']))
            ->assertOk()
            ->assertSee('Widget origin filter sentinel')
            ->assertDontSee('Dashboard origin filter sentinel');

        $this->actingAs($admin)
            ->get(route('admin.support.management', ['topic_area' => 'services']))
            ->assertOk()
            ->assertSee('Dashboard origin filter sentinel')
            ->assertDontSee('Widget origin filter sentinel');
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
        $this->assertStringContainsString('if (pollInFlight || olderInFlight || document.hidden) return;', $html);
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


    public function test_admin_thread_loads_only_the_latest_hundred_messages_and_pages_older_history(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Bounded admin history',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $ids = [];
        for ($i = 1; $i <= 105; $i++) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $admin->id,
                'body' => $i <= 5 ? 'OLD_ONLY_MESSAGE_'.$i : 'RECENT_MESSAGE_'.$i,
                'is_internal' => false,
                'is_staff' => true,
            ]);
            $ids[] = $message->id;
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('RECENT_MESSAGE_105')
            ->assertDontSee('OLD_ONLY_MESSAGE_1')
            ->assertSee('loadOlderAdminMessages');

        $this->assertSame(100, substr_count($response->getContent(), 'data-msg-id='));

        $this->actingAs($admin)
            ->get(route('support.messages.poll', $ticket).'?before_id='.$ids[5])
            ->assertOk()
            ->assertJsonCount(5, 'messages')
            ->assertJsonPath('messages.0.body', 'OLD_ONLY_MESSAGE_1')
            ->assertJsonPath('messages.4.body', 'OLD_ONLY_MESSAGE_5')
            ->assertJsonPath('next_before_id', $ids[0])
            ->assertJsonPath('has_more', false);
    }


    public function test_admin_inbox_combines_unassigned_and_created_date_filters_with_existing_dimensions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $from = Carbon::today()->subDays(10);
        $to = Carbon::today()->subDays(2);

        $createTicketAt = function (array $attributes, Carbon $createdAt): Ticket {
            $ticket = Ticket::create($attributes);
            $ticket->forceFill(['created_at' => $createdAt])->save();

            return $ticket;
        };

        $createTicketAt([
            'subject' => 'Unassigned date-range match',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
            'assigned_to' => null,
            'source' => 'widget',
            'topic_area' => 'store',
        ], $from->copy()->addDays(3)->setTime(12, 0));
        $createTicketAt([
            'subject' => 'Assigned same date range',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
            'assigned_to' => $admin->id,
            'source' => 'widget',
            'topic_area' => 'store',
        ], $from->copy()->addDays(4)->setTime(12, 0));
        $createTicketAt([
            'subject' => 'Outside date range',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
            'assigned_to' => null,
            'source' => 'widget',
            'topic_area' => 'store',
        ], $to->copy()->addDay()->setTime(12, 0));
        $createTicketAt([
            'subject' => 'Other channel in date range',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
            'assigned_to' => null,
            'source' => 'dashboard',
            'topic_area' => 'store',
        ], $from->copy()->addDays(5)->setTime(12, 0));

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', [
                'assigned' => 'unassigned',
                'source' => 'widget',
                'topic_area' => 'store',
                'created_from' => $from->toDateString(),
                'created_to' => $to->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Unassigned date-range match')
            ->assertDontSee('Assigned same date range')
            ->assertDontSee('Outside date range')
            ->assertDontSee('Other channel in date range');

        $html = $response->getContent();
        $this->assertStringContainsString('name="created_from"', $html);
        $this->assertStringContainsString('name="created_to"', $html);
        $this->assertStringContainsString('name="assigned"', $html);
        $this->assertStringContainsString('assigned=unassigned', $html);
        $this->assertStringContainsString('created_from='.$from->toDateString(), $html);

        preg_match('/href="([^"]*status=needs_reply[^"]*)"/', $html, $statusChipMatch);
        $this->assertNotEmpty($statusChipMatch[1] ?? null);
        $this->assertStringContainsString('assigned=unassigned', $statusChipMatch[1]);
        $this->assertStringContainsString('created_from=', $statusChipMatch[1]);
    }

    public function test_unknown_inbox_filter_values_are_ignored_and_reversed_date_ranges_do_not_hide_tickets(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Ticket::create([
            'subject' => 'Invalid filter values must not hide this ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'source' => 'dashboard',
            'topic_area' => 'services',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.support.management', [
                'status' => 'not-a-status',
                'department' => 'not-a-department',
                'priority' => 'urgent',
                'source' => 'unknown-source',
                'topic_area' => 'unknown-topic',
                'assigned' => 'unknown-assignee-filter',
                'sla' => 'unknown-sla-filter',
                'created_from' => '2099-01-01',
                'created_to' => '2000-01-01',
            ]))
            ->assertOk()
            ->assertSee('Invalid filter values must not hide this ticket');
    }

    public function test_quick_filter_links_preserve_zero_search_term_and_other_filters(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->get(route('admin.support.management', [
                'q' => '0',
                'assigned' => 'unassigned',
                'created_from' => '2026-11-01',
                'created_to' => '2026-11-14',
            ]))
            ->assertOk();

        preg_match('/href="([^"]*status=needs_reply[^"]*)"/', $response->getContent(), $statusChipMatch);

        $this->assertNotEmpty($statusChipMatch[1] ?? null);
        $this->assertStringContainsString('q=0', $statusChipMatch[1]);
        $this->assertStringContainsString('assigned=unassigned', $statusChipMatch[1]);
        $this->assertStringContainsString('created_from=2026-11-01', $statusChipMatch[1]);
        $this->assertStringContainsString('created_to=2026-11-14', $statusChipMatch[1]);
    }


    public function test_staff_reply_preserves_all_inbox_filters_without_confusing_reply_status_with_filter_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $ticket = Ticket::create([
            'subject' => 'Reply preserves inbox filter context',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'source' => 'widget',
            'topic_area' => 'store',
        ]);

        $filters = [
            'status' => 'open',
            'q' => 'filter-context',
            'department' => 'support',
            'priority' => 'normal',
            'source' => 'widget',
            'topic_area' => 'store',
            'sla' => 'breached',
            'assigned' => 'unassigned',
            'created_from' => '2026-11-01',
            'created_to' => '2026-11-15',
        ];

        $page = $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id] + $filters))
            ->assertOk();

        preg_match('/<form class="sd-composer"[^>]*action="([^"]+)"/', $page->getContent(), $formMatch);
        $this->assertNotEmpty($formMatch[1] ?? null);
        $formAction = html_entity_decode($formMatch[1], ENT_QUOTES);
        parse_str((string) parse_url($formAction, PHP_URL_QUERY), $formQuery);
        $this->assertSame((string) $ticket->id, (string) ($formQuery['ticket'] ?? ''));
        foreach ($filters as $key => $value) {
            $this->assertSame($value, $formQuery[$key] ?? null, 'Missing inbox filter in reply form action: '.$key);
        }

        $tickets = Mockery::mock(SupportTicketService::class);
        $tickets->shouldReceive('addMessage')
            ->once()
            ->withArgs(function ($candidate, $actor, $body, $options) use ($ticket, $admin): bool {
                return $candidate instanceof Ticket
                    && $candidate->is($ticket)
                    && $actor instanceof User
                    && (int) $actor->id === (int) $admin->id
                    && $body === 'پاسخ کارشناسی'
                    && ($options['status'] ?? null) === 'answered'
                    && ($options['is_staff'] ?? false) === true;
            })
            ->andReturn(new TicketMessage());
        $this->app->instance(SupportTicketService::class, $tickets);

        $this->post(
            route('admin.tickets.reply', $ticket).'?'.http_build_query(['ticket' => $ticket->id] + $filters),
            [
                'body' => 'پاسخ کارشناسی',
                'status' => 'answered',
                'submission_key' => '9f8d5e10-9a6b-4f4f-8b20-4c5b7c0e6d11',
            ]
        )->assertRedirect(route('admin.support.management', ['ticket' => $ticket->id] + $filters));
    }


    public function test_scoped_inbox_keeps_staff_assignees_from_visible_tickets_in_the_filter(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Scoped Inbox Staff Assignee']);

        Ticket::create([
            'user_id' => $admin->id,
            'subject' => 'Ticket in scoped inbox with staff assignee',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $agent->id,
        ]);

        // visibleUserIds contains ticket owners, not staff/admin assignees.
        // Assignee choices must be derived from the already-scoped ticket set.
        $console = Mockery::mock(AdminConsoleService::class);
        $console->shouldReceive('visibleUserIds')->once()->with($admin)->andReturn([$admin->id]);
        $console->shouldReceive('sectionAllowed')->andReturn(true);
        $this->app->instance(AdminConsoleService::class, $console);

        $this->actingAs($admin)
            ->get(route('admin.support.management'))
            ->assertOk()
            ->assertSee('Ticket in scoped inbox with staff assignee')
            ->assertSee('Scoped Inbox Staff Assignee');
    }


    public function test_admin_inbox_filters_tickets_by_a_specific_assignee_from_scoped_ticket_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agentA = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Inbox Agent Alpha']);
        $agentB = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Inbox Agent Beta']);

        Ticket::create([
            'subject' => 'Ticket assigned to alpha',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $agentA->id,
        ]);
        Ticket::create([
            'subject' => 'Ticket assigned to beta',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $agentB->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.support.management', ['assigned' => (string) $agentA->id]))
            ->assertOk()
            ->assertSee('Ticket assigned to alpha')
            ->assertDontSee('Ticket assigned to beta')
            ->assertSee('Inbox Agent Alpha')
            ->assertSee('Inbox Agent Beta');

        $this->actingAs($admin)
            ->get(route('admin.support.management', ['assigned' => (string) $agentB->id]))
            ->assertOk()
            ->assertSee('Ticket assigned to beta')
            ->assertDontSee('Ticket assigned to alpha');
    }


}
