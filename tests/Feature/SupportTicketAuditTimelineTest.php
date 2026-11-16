<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\AuditEventService;
use App\Services\SupportNotificationService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SupportTicketAuditTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_scoped_operator_does_not_see_out_of_scope_audit_actor_name(): void
    {
        config(['farast_admin.multi_role_console' => true]);

        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $teamAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $teamMate = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $outsideAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $customer = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Audit timeline organization',
            'slug' => 'audit-timeline-organization',
            'status' => 'active',
        ]);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Visible support team',
            'slug' => 'visible-support-team',
            'supervisor_id' => $teamAgent->id,
        ]);
        $otherTeam = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Other support team',
            'slug' => 'other-support-team',
            'supervisor_id' => $outsideAgent->id,
        ]);
        $team->members()->attach($teamAgent->id, ['team_role' => 'manager']);
        $team->members()->attach($teamMate->id, ['team_role' => 'manager']);
        $team->members()->attach($customer->id, ['team_role' => 'employee']);
        $otherTeam->members()->attach($outsideAgent->id, ['team_role' => 'manager']);

        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        DB::table('roles')->insert([
            'key' => 'support_audit_timeline_operator',
            'name' => 'Support audit timeline operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'support_audit_timeline_operator',
            'permission_id' => $permission->id,
        ]);

        $authorization = app(AuthorizationService::class);
        $authorization->assignRole($root, $teamAgent, 'support_audit_timeline_operator', 'team', $organization->id, $team->id);
        $authorization->assignRole($root, $teamMate, 'support_audit_timeline_operator', 'team', $organization->id, $team->id);
        $authorization->assignRole($root, $outsideAgent, 'support_audit_timeline_operator', 'team', $organization->id, $otherTeam->id);

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Scoped audit timeline ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $audit = app(AuditEventService::class);
        $audit->record(
            'support.ticket.status_transitioned',
            $teamMate->id,
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
        $audit->record(
            'support.ticket.assignment_changed',
            $outsideAgent->id,
            null,
            'ticket',
            $ticket->id,
            ['from_assigned_to' => null, 'to_assigned_to' => $outsideAgent->id],
            'support'
        );

        $response = $this->actingAs($teamAgent)->get(
            route('admin.support.management', ['ticket' => $ticket->id])
        );

        $response->assertOk()
            ->assertSee('کارشناس دیگر')
            ->assertSee($teamMate->name)
            ->assertDontSee($outsideAgent->name);
    }

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

    public function test_sla_alert_queue_and_delivery_are_visible_in_the_canonical_ticket_timeline(): void
    {
        config(['support.business_hours_only' => false, 'support.warn_before_minutes' => 30]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'SLA audit timeline subject',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subHour(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->once()->withArgs(
            fn ($candidate, $kind, $identity): bool => $candidate instanceof Ticket
                && $candidate->is($ticket)
                && $kind === 'first_response'
                && is_string($identity)
        );
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $queuedEvent = DB::table('farast_audit_events')
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->where('event_type', 'support.ticket.sla_alert_queued')
            ->first();
        $this->assertNotNull($queuedEvent);

        $queuedMeta = json_decode($queuedEvent->payload_meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('first_response', $queuedMeta['kind']);
        $this->assertSame('breached', $queuedMeta['event']);
        $this->assertSame('support', $queuedEvent->source);

        Artisan::call('support:deliver-sla-alerts');

        $dispatchedEvent = DB::table('farast_audit_events')
            ->where('aggregate_type', 'ticket')
            ->where('aggregate_id', $ticket->id)
            ->where('event_type', 'support.ticket.sla_alert_dispatched')
            ->first();
        $this->assertNotNull($dispatchedEvent);

        $response = $this->actingAs($admin)->get(
            route('admin.support.management', ['ticket' => $ticket->id])
        );

        $response->assertOk()
            ->assertSee('ثبت هشدار SLA در صف ارسال')
            ->assertSee('تحویل هشدار SLA به کانال‌های اطلاع‌رسانی')
            ->assertSee('اولین پاسخ')
            ->assertSee('نقض مهلت')
            ->assertDontSee('event_identity');
    }


    public function test_message_and_csat_events_have_safe_timeline_labels(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Message and CSAT timeline',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $service = app(SupportTicketService::class);
        $service->addMessage($ticket, $admin, 'Private internal note body', [
            'is_staff' => true,
            'is_internal' => true,
            'touch_status' => false,
            'notify' => false,
        ]);
        $service->submitCsat($ticket, 4, 'Private CSAT comment', $customer);

        $response = $this->actingAs($admin)->get(
            route('admin.support.management', ['ticket' => $ticket->id])
        );

        $response->assertOk()
            ->assertSee('ثبت یادداشت داخلی در گفتگو')
            ->assertSee('ثبت امتیاز رضایت مشتری')
            ->assertSee('امتیاز ثبت‌شده: 4 از ۵');
    }


    public function test_reopen_timeline_displays_reopen_and_previous_lifecycle_timestamps(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $closedAt = now()->subMinutes(30)->startOfSecond();
        $resolvedAt = now()->subMinutes(25)->startOfSecond();
        $ticket = Ticket::create([
            'subject' => 'Reopen timeline lifecycle evidence',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'closed_at' => $closedAt,
            'resolved_at' => $resolvedAt,
        ]);

        app(SupportTicketService::class)->addMessage($ticket, $admin, 'پاسخ پس از بازگشایی', [
            'is_staff' => true,
            'status' => 'answered',
            'notify' => false,
        ]);

        $response = $this->actingAs($admin)->get(
            route('admin.support.management', ['ticket' => $ticket->id])
        );

        $response->assertOk()
            ->assertSee('زمان بازگشایی:')
            ->assertSee('آخرین زمان بسته‌شدن:')
            ->assertSee('آخرین زمان حل:');
    }

}
