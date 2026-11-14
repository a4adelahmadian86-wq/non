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

}
