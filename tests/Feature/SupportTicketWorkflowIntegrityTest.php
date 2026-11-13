<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\StorageManagedFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SupportTicketWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SupportTicketService
    {
        return new SupportTicketService(
            Mockery::mock(SupportNotificationService::class)->shouldIgnoreMissing(),
            new SupportSlaService(),
        );
    }

    private function supportAgent(): User
    {
        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $agent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        DB::table('roles')->insert([
            'key' => 'support_operator_workflow_test',
            'name' => 'Support operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'support_operator_workflow_test',
            'permission_id' => $permission->id,
        ]);

        app(AuthorizationService::class)->assignRole(
            $root,
            $agent,
            'support_operator_workflow_test',
            'global',
        );

        return $agent->fresh();
    }

    public function test_reopening_ticket_clears_both_closed_and_resolved_timestamps(): void
    {
        $closedAt = now()->subHour();
        $ticket = Ticket::create([
            'subject' => 'Reopen integrity',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'closed_at' => $closedAt,
            'resolved_at' => $closedAt,
        ]);

        $updated = $this->service()->changeStatus($ticket, 'open', null, false);

        $this->assertNull($updated->closed_at);
        $this->assertNull($updated->resolved_at);
        $this->assertSame('open', $updated->status);
    }

    public function test_pending_ticket_can_still_breach_first_response_sla(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Pending first response breach',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'sla_resolve_due_at' => now()->addHour(),
            'sla_paused_at' => now()->subMinutes(2),
            'sla_resolve_remaining_minutes' => 60,
        ]);

        $this->assertTrue(
            Ticket::query()->slaBreached()->whereKey($ticket->id)->exists(),
            'Pending pauses resolution SLA, not the first-response SLA.'
        );
    }

    public function test_pending_ticket_resolution_breach_is_not_counted_while_paused(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Pending resolution pause',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'first_response_at' => now()->subHour(),
            'sla_first_due_at' => now()->subHours(2),
            'sla_resolve_due_at' => now()->subMinutes(5),
            'sla_paused_at' => now()->subMinutes(10),
            'sla_resolve_remaining_minutes' => 30,
        ]);

        $this->assertFalse(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
    }

    public function test_custom_rbac_support_agent_is_eligible_for_assignment_without_admin_role(): void
    {
        $agent = $this->supportAgent();
        $ticket = Ticket::create([
            'subject' => 'Scoped assignment',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $service = $this->service();
        $eligibleIds = $service->eligibleAgentsFor($ticket)->modelKeys();

        $this->assertContains($agent->id, $eligibleIds);
        $assigned = $service->assign($ticket, $agent);

        $this->assertSame($agent->id, (int) $assigned->assigned_to);
    }

    public function test_deleted_managed_ticket_attachment_is_never_served(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Deleted attachment must stay private',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);
        $message = $ticket->messages()->create([
            'user_id' => $customer->id,
            'body' => 'attachment',
            'is_internal' => false,
            'is_staff' => false,
        ]);

        $volumeId = DB::table('storage_volumes')->insertGetId([
            'drive_key' => 'foreign_primary',
            'name' => 'Ticket attachment test volume',
            'driver' => 'local',
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managed = StorageManagedFile::create([
            'storage_volume_id' => $volumeId,
            'drive_key' => 'foreign_primary',
            'file_key' => 'support/2026/11/deleted.txt',
            'original_name' => 'deleted.txt',
            'mime' => 'text/plain',
            'size_bytes' => 7,
            'checksum' => hash('sha256', 'deleted'),
            'classification' => 'non_critical',
            'source_module' => 'support',
            'source_type' => \App\Models\TicketMessage::class,
            'source_id' => $message->id,
            'visibility' => 'private',
            'status' => 'deleted',
            'metadata' => ['disk' => 'storage_volume:'.$volumeId],
        ]);

        $message->update(['attachments' => [[
            'managed_file_id' => $managed->id,
            'path' => $managed->file_key,
            'disk' => 'storage_volume:'.$volumeId,
            'name' => 'deleted.txt',
        ]]]);

        $this->actingAs($customer)
            ->get(route('support.attachment', [
                'ticket' => $ticket->id,
                'message' => $message->id,
                'index' => 0,
            ]))
            ->assertNotFound();
    }

    public function test_user_without_support_permission_cannot_be_assigned(): void
    {
        $unauthorized = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $ticket = Ticket::create([
            'subject' => 'Reject invalid assignee',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->assign($ticket, $unauthorized);
    }
}
