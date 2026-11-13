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
use Tests\TestCase;

class SupportTicketScopedAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function supportAgent(): User
    {
        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_USER]);
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
}
