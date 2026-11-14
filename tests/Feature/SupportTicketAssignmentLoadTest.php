<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Http\Middleware\ResolveAdminScope;
use App\Services\AuthorizationService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportTicketAssignmentLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_assignment_ignores_workload_outside_each_agents_rbac_scope(): void
    {
        config(['farast_admin.multi_role_console' => true]);

        $root = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $teamAgent = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $insideCustomer = User::factory()->create();
        $outsideCustomer = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Assignment scope organization',
            'slug' => 'assignment-scope-organization',
            'status' => 'active',
        ]);
        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Assignment scope team',
            'slug' => 'assignment-scope-team',
            'supervisor_id' => $teamAgent->id,
        ]);
        $team->members()->attach($teamAgent->id, ['team_role' => 'manager']);
        $team->members()->attach($insideCustomer->id, ['team_role' => 'employee']);

        $permission = Permission::firstOrCreate(
            ['key' => 'support.manage'],
            ['label' => 'Support management', 'group' => 'support'],
        );
        DB::table('roles')->insert([
            'key' => 'team_support_assignment_operator',
            'name' => 'Team support assignment operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'team_support_assignment_operator',
            'permission_id' => $permission->id,
        ]);
        app(AuthorizationService::class)->assignRole(
            $root,
            $teamAgent,
            'team_support_assignment_operator',
            'team',
            $organization->id,
            $team->id,
        );

        $target = Ticket::create([
            'user_id' => $insideCustomer->id,
            'subject' => 'Ticket to route by visible workload',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        Ticket::create([
            'user_id' => $insideCustomer->id,
            'assigned_to' => $root->id,
            'subject' => 'Global operator visible workload',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
        ]);

        for ($i = 1; $i <= 4; $i++) {
            Ticket::create([
                'user_id' => $outsideCustomer->id,
                'assigned_to' => $teamAgent->id,
                'subject' => 'Out of scope workload '.$i,
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
            ]);
        }

        // The request's resolved scope belongs to the current requester,
        // not to the candidate agent. Assignment workload must resolve each
        // candidate's own effective scope.
        request()->attributes->set('farast.scope', 'global');
        $agentVisibleUserIds = ResolveAdminScope::visibleUserIds($teamAgent);
        $this->assertContains($insideCustomer->id, $agentVisibleUserIds);
        $this->assertNotContains($outsideCustomer->id, $agentVisibleUserIds);

        $picked = DB::transaction(
            fn () => app(SupportTicketService::class)->pickLeastLoadedAgent($target)
        );

        $this->assertNotNull($picked);
        $this->assertSame($teamAgent->id, $picked->id);
    }
}
