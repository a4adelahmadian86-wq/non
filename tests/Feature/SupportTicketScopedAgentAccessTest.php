<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\SiteSetting;
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
                'submission_key' => 'c6f9d230-6b3c-4da2-8fd9-9b1e8f5c4433',
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

    public function test_scoped_admin_is_not_an_eligible_assignee_outside_its_ticket_scope(): void
    {
        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        $organization = Organization::create(['name' => 'Assignee Scope Org', 'slug' => 'assignee-scope-org', 'status' => 'active']);
        $otherOrganization = Organization::create(['name' => 'Other Assignee Org', 'slug' => 'other-assignee-org', 'status' => 'active']);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => $organization->id,
            'is_blocked' => false,
        ]);
        $insideCustomer = User::factory()->create(['organization_id' => $organization->id, 'is_blocked' => false]);
        $outsideCustomer = User::factory()->create(['organization_id' => $otherOrganization->id, 'is_blocked' => false]);
        SiteSetting::write('dashboard_scope_override', 'organization');
        request()->attributes->set('farast.scope', 'organization');

        $insideTicket = Ticket::create([
            'user_id' => $insideCustomer->id,
            'subject' => 'Ticket inside assignee scope',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $outsideTicket = Ticket::create([
            'user_id' => $outsideCustomer->id,
            'subject' => 'Ticket outside assignee scope',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $directory = app(\App\Services\SupportAgentDirectory::class);
        $this->assertTrue($directory->allows($admin, $insideTicket));
        $this->assertFalse($directory->allows($admin, $outsideTicket));
        $this->assertTrue($directory->eligibleFor($insideTicket)->contains('id', $admin->id));
        $this->assertFalse($directory->eligibleFor($outsideTicket)->contains('id', $admin->id));
    }
    public function test_admin_assignee_uses_its_own_team_scope_not_the_acting_admin_request_scope(): void
    {
        config(['farast_admin.multi_role_console' => true, 'farast_admin.scope_override_key' => '']);
        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $candidate = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create();
        $outsideCustomer = User::factory()->create();
        $organization = Organization::create(['name' => 'Admin Assignee Org', 'slug' => 'admin-assignee-org', 'status' => 'active']);
        $team = \App\Models\Team::create([
            'organization_id' => $organization->id,
            'name' => 'Admin Assignee Team',
            'slug' => 'admin-assignee-team',
            'supervisor_id' => $root->id,
        ]);
        $team->members()->attach($customer->id, ['team_role' => 'employee']);

        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        $roleId = DB::table('roles')->insertGetId([
            'key' => 'admin_team_support_operator',
            'name' => 'Admin team support operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'admin_team_support_operator',
            'permission_id' => $permission->id,
        ]);
        DB::table('users')->where('id', $candidate->id)->update([
            'access_control_managed' => true,
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->insert([
            'user_id' => $candidate->id,
            'role_id' => $roleId,
            'scope' => 'team',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'assigned_by' => $root->id,
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insideTicket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Admin candidate in team scope',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $outsideTicket = Ticket::create([
            'user_id' => $outsideCustomer->id,
            'subject' => 'Admin candidate outside team scope',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        // Simulate a global actor assigning work while the candidate has a narrower team grant.
        request()->attributes->set('farast.scope', 'global');
        $directory = app(\App\Services\SupportAgentDirectory::class);
        $this->assertTrue($directory->allows($candidate, $insideTicket));
        $this->assertFalse($directory->allows($candidate, $outsideTicket));
        $this->assertTrue($directory->eligibleFor($insideTicket)->contains('id', $candidate->id));
        $this->assertFalse($directory->eligibleFor($outsideTicket)->contains('id', $candidate->id));
    }
    public function test_global_support_operator_communication_center_can_search_internal_notes(): void
    {
        config(['farast_admin.multi_role_console' => true]);
        $agent = $this->supportAgent();
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Global support operator ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'global-support-internal-search-615',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $this->actingAs($agent)
            ->get(route('platform.communications', ['q' => 'global-support-internal-search-615']))
            ->assertOk()
            ->assertSee('global-support-internal-search-615')
            ->assertSee(route('admin.support.management', ['ticket' => $ticket->id]));
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

    public function test_admin_role_does_not_bypass_organization_scope_on_direct_ticket_routes(): void
    {
        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        $organization = Organization::create(['name' => 'Scoped Admin Org', 'slug' => 'scoped-admin-org', 'status' => 'active']);
        $otherOrganization = Organization::create(['name' => 'Foreign Admin Org', 'slug' => 'foreign-admin-org', 'status' => 'active']);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'organization_id' => $organization->id,
            'is_blocked' => false,
        ]);
        $insideCustomer = User::factory()->create(['organization_id' => $organization->id, 'is_blocked' => false]);
        $outsideCustomer = User::factory()->create(['organization_id' => $otherOrganization->id, 'is_blocked' => false]);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $insideTicket = Ticket::create([
            'user_id' => $insideCustomer->id,
            'subject' => 'Scoped admin visible ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $outsideTicket = Ticket::create([
            'user_id' => $outsideCustomer->id,
            'subject' => 'Scoped admin forbidden ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->actingAs($admin)
            ->get(route('support.show', $insideTicket))
            ->assertOk()
            ->assertSee('Scoped admin visible ticket');

        $this->get(route('support.show', $outsideTicket))->assertForbidden();

        $this->post(route('admin.tickets.reply', $insideTicket), ['body' => 'پاسخ مجاز در دامنه سازمان'])
            ->assertRedirect();
        $this->post(route('admin.tickets.reply', $outsideTicket), ['body' => 'پاسخ خارج از دامنه'])
            ->assertForbidden();

        $this->get(route('admin.support.management', ['ticket' => $insideTicket->id]))
            ->assertOk()
            ->assertSee('Scoped admin visible ticket')
            ->assertDontSee('Scoped admin forbidden ticket');
    }
    public function test_scoped_support_admin_does_not_see_audit_actor_names_outside_its_scope(): void
    {
        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        $organization = Organization::create(['name' => 'Audit Scope Org', 'slug' => 'audit-scope-org', 'status' => 'active']);
        $otherOrganization = Organization::create(['name' => 'Other Audit Org', 'slug' => 'other-audit-org', 'status' => 'active']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'organization_id' => $organization->id, 'is_blocked' => false]);
        $customer = User::factory()->create(['organization_id' => $organization->id, 'is_blocked' => false]);
        $outsideAgent = User::factory()->create(['role' => User::ROLE_ADMIN, 'organization_id' => $otherOrganization->id, 'name' => 'Outside Scope Agent Secret', 'is_blocked' => false]);
        SiteSetting::write('dashboard_scope_override', 'organization');

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Scoped audit history ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        app(\App\Services\AuditEventService::class)->record(
            'support.ticket.assignment_changed',
            (int) $outsideAgent->id,
            null,
            'ticket',
            (int) $ticket->id,
            ['from_assigned_to' => null, 'to_assigned_to' => (int) $outsideAgent->id],
            'support',
        );

        $this->actingAs($admin)
            ->get(route('admin.support.management', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee('Scoped audit history ticket')
            ->assertSee('کارشناس دیگر')
            ->assertDontSee('Outside Scope Agent Secret');
    }
    public function test_own_scope_admin_can_view_own_ticket_but_cannot_read_internal_notes_or_attachments(): void
    {
        config(['farast_admin.scope_override_key' => 'dashboard_scope_override']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_blocked' => false]);
        SiteSetting::write('dashboard_scope_override', 'own');
        $ticket = Ticket::create([
            'user_id' => $admin->id,
            'subject' => 'Own-scope admin ticket',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $internal = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => 'own-scope-hidden-internal-note-911',
            'is_internal' => true,
            'is_staff' => true,
            'attachments' => [[
                'name' => 'private-note.txt',
                'path' => 'private/own-scope-note.txt',
                'disk' => 'private',
                'managed_file_id' => 999999,
            ]],
        ]);

        $this->actingAs($admin)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee('Own-scope admin ticket')
            ->assertDontSee('own-scope-hidden-internal-note-911');

        $this->get(route('support.messages.poll', $ticket))
            ->assertOk()
            ->assertJsonMissing(['body' => 'own-scope-hidden-internal-note-911']);

        $this->get(route('support.attachment', ['ticket' => $ticket->id, 'message' => $internal->id, 'index' => 0]))
            ->assertNotFound();
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

        $firstInternal = TicketMessage::create([
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

        $this->actingAs($customer)
            ->get(route('support.messages.poll', $ticket).'?before_id='.$secondInternal->id)
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', 'public response')
            ->assertJsonPath('next_before_id', $firstInternal->id)
            ->assertJsonPath('has_more', false)
            ->assertJsonMissing(['body' => 'private note one'])
            ->assertJsonMissing(['body' => 'private note two']);
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

        self::assertSame('team', app(AuthorizationService::class)->effectiveScope($agent));
        $visibleUserIds = app(\App\Services\AdminConsoleService::class)->visibleUserIds($agent);
        self::assertContains($customer->id, $visibleUserIds);
        self::assertNotContains($outsideCustomer->id, $visibleUserIds);

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

        $searchResponse = $this->actingAs($agent)
            ->get(route('admin.support.management', ['q' => 'Other-team private ticket']))
            ->assertOk()
            ->assertDontSee('Global-only guest ticket');

        // The query text is echoed in the search input; assert the foreign ticket
        // itself is not linked into the rendered inbox.
        $this->assertStringNotContainsString('ticket='.$outsideTicket->id, $searchResponse->getContent());

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


    public function test_customer_thread_loads_a_bounded_history_and_older_pages_never_expose_internal_notes(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Bounded customer history',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $ids = [];
        for ($i = 1; $i <= 105; $i++) {
            $internal = in_array($i, [1, 3], true);
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $internal ? null : $customer->id,
                'body' => $internal ? 'SECRET_INTERNAL_'.$i : ($i <= 5 ? 'OLD_PUBLIC_'.$i : 'RECENT_PUBLIC_'.$i),
                'is_internal' => $internal,
                'is_staff' => $internal,
            ]);
            $ids[] = $message->id;
        }

        $response = $this->actingAs($customer)
            ->get(route('support.show', $ticket))
            ->assertOk()
            ->assertSee('RECENT_PUBLIC_105')
            ->assertDontSee('OLD_PUBLIC_2')
            ->assertDontSee('SECRET_INTERNAL_3')
            ->assertSee('loadOlderTicketMessages');

        $this->assertSame(100, substr_count($response->getContent(), 'data-msg-id='));

        $this->actingAs($customer)
            ->get(route('support.messages.poll', $ticket).'?before_id='.$ids[5])
            ->assertOk()
            ->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.0.body', 'OLD_PUBLIC_2')
            ->assertJsonPath('messages.1.body', 'OLD_PUBLIC_4')
            ->assertJsonPath('messages.2.body', 'OLD_PUBLIC_5')
            ->assertJsonPath('next_before_id', $ids[0])
            ->assertJsonPath('has_more', false)
            ->assertJsonMissing(['body' => 'SECRET_INTERNAL_1'])
            ->assertJsonMissing(['body' => 'SECRET_INTERNAL_3']);
    }

}
