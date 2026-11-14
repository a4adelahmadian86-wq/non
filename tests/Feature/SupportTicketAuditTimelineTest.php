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
}
