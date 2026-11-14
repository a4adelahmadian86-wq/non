<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SupportTicketScopedAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function supportAgent(): User
    {
        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'support_operator',
            'name' => 'Support operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'support_operator',
            'permission_id' => $permission->id,
        ]);

        app(AuthorizationService::class)->assignRole(
            $root,
            $agent,
            'support_operator',
            'global',
        );

        return $agent->fresh();
    }

    public function test_global_support_agent_can_manage_tickets_and_read_internal_notes_without_admin_role(): void
    {
        config(['farast_admin.multi_role_console' => true]);
        $agent = $this->supportAgent();
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Scoped support access',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->actingAs($agent)
            ->post(route('admin.tickets.reply', $ticket), [
                'body' => 'یادداشت داخلی کارشناس',
                'is_internal' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'یادداشت داخلی کارشناس',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $this->actingAs($agent)
            ->get(route('support.messages.poll', $ticket))
            ->assertOk()
            ->assertJsonFragment(['body' => 'یادداشت داخلی کارشناس']);
    }

    public function test_support_agent_can_view_ticket_but_cannot_submit_customer_csat(): void
    {
        $agent = $this->supportAgent();
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Customer-owned feedback',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->assertTrue(Gate::forUser($agent)->allows('view', $ticket));
        $this->assertFalse(Gate::forUser($agent)->allows('rate', $ticket));
        $this->assertTrue(Gate::forUser($customer)->allows('rate', $ticket));
    }

    public function test_customer_can_never_read_staff_internal_notes(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Internal note isolation',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => 'private staff note',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('support.messages.poll', $ticket))
            ->assertOk()
            ->assertJsonMissing(['body' => 'private staff note']);
    }

    public function test_customer_poll_cursor_advances_over_hidden_internal_notes(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Hidden-note polling cursor',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => 'private note one',
            'is_internal' => true,
            'is_staff' => true,
        ]);
        $public = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'public response',
            'is_internal' => false,
            'is_staff' => false,
        ]);

        $this->actingAs($customer)
            ->get(route('support.messages.poll', $ticket).'?after_id=0')
            ->assertOk()
            ->assertJsonPath('last_id', $public->id)
            ->assertJsonFragment(['body' => 'public response'])
            ->assertJsonMissing(['body' => 'private note one']);

        $ticket->forceFill(['customer_last_seen_at' => null])->saveQuietly();
        $secondInternal = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => 'private note two',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('support.messages.poll', $ticket).'?after_id='.$public->id)
            ->assertOk()
            ->assertJsonPath('messages', [])
            ->assertJsonPath('last_id', $secondInternal->id)
            ->assertJsonMissing(['body' => 'private note two']);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'customer_last_seen_at' => null,
        ]);
    }

    public function test_team_scoped_support_agent_only_sees_and_manages_tickets_owned_by_team_members(): void
    {
        config(['farast_admin.multi_role_console' => true]);

        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $customer = User::factory()->create();
        $outsideCustomer = User::factory()->create();

        $organization = \App\Models\Organization::create([
            'name' => 'Support scope organization',
            'slug' => 'support-scope-organization',
            'status' => 'active',
        ]);
        $team = \App\Models\Team::create([
            'organization_id' => $organization->id,
            'name' => 'Support team',
            'slug' => 'support-team',
            'supervisor_id' => $agent->id,
        ]);
        $team->members()->attach($agent->id, ['team_role' => 'manager']);
        $team->members()->attach($customer->id, ['team_role' => 'employee']);

        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        DB::table('roles')->insert([
            'key' => 'team_support_operator',
            'name' => 'Team support operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'team_support_operator',
            'permission_id' => $permission->id,
        ]);
        app(AuthorizationService::class)->assignRole(
            $root,
            $agent,
            'team_support_operator',
            'team',
            $organization->id,
            $team->id,
        );

        $insideTicket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Team-owned support ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $outsideTicket = Ticket::create([
            'user_id' => $outsideCustomer->id,
            'subject' => 'Other-team private ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $guestTicket = Ticket::create([
            'user_id' => null,
            'subject' => 'Global-only guest ticket',
            'guest_email' => 'guest@example.test',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->actingAs($agent)
            ->get(route('admin.support.management', ['ticket' => $insideTicket->id]))
            ->assertOk()
            ->assertSee('Team-owned support ticket')
            ->assertDontSee('Other-team private ticket')
            ->assertDontSee('Global-only guest ticket')
            ->assertDontSee('گزارش SLA');

        $this->actingAs($agent)
            ->get(route('admin.support.management', ['ticket' => $outsideTicket->id]))
            ->assertOk()
            ->assertSee('Team-owned support ticket')
            ->assertDontSee('Other-team private ticket')
            ->assertDontSee('Global-only guest ticket');

        $this->actingAs($agent)
            ->get(route('admin.support.management', ['q' => 'Other-team private ticket']))
            ->assertOk()
            ->assertDontSee('Other-team private ticket')
            ->assertDontSee('Global-only guest ticket');

        $this->actingAs($agent)
            ->post(route('admin.tickets.reply', $outsideTicket), ['body' => 'Unauthorized reply'])
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('admin.support.report'))
            ->assertForbidden();

        // A stale team assignment must not keep record-level access after removal.
        $team->members()->detach($agent->id);
        $team->update(['supervisor_id' => null]);
        $this->actingAs($agent)
            ->get(route('admin.support.management'))
            ->assertForbidden();
        $this->actingAs($agent)
            ->post(route('admin.tickets.reply', $insideTicket), ['body' => 'Stale team access'])
            ->assertForbidden();
    }

}
