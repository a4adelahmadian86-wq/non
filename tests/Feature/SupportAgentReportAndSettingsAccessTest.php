<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Http\Controllers\SupportReportController;
use App\Services\AuthorizationService;
use App\Services\SupportSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportAgentReportAndSettingsAccessTest extends TestCase
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

        DB::table('roles')->insert([
            'key' => 'support_operator_report_test',
            'name' => 'Support operator',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RolePermission::firstOrCreate([
            'role' => 'support_operator_report_test',
            'permission_id' => $permission->id,
        ]);

        app(AuthorizationService::class)->assignRole(
            $root,
            $agent,
            'support_operator_report_test',
            'global',
        );

        return $agent->fresh();
    }

    public function test_scoped_support_agent_can_open_support_performance_report(): void
    {
        config(['farast_admin.multi_role_console' => true]);

        $this->actingAs($this->supportAgent())
            ->get(route('admin.support.report'))
            ->assertOk();
    }

    public function test_support_report_aggregates_csat_in_sql_and_respects_the_selected_date_range(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $from = now()->subDays(4)->startOfDay();
        $to = now()->subDay()->endOfDay();

        foreach ([1, 2, 2, 4, 5] as $index => $score) {
            Ticket::create([
                'subject' => 'CSAT aggregate '.$index,
                'status' => 'closed',
                'priority' => 'normal',
                'department' => 'support',
                'csat_score' => $score,
                'csat_at' => $from->copy()->addHours($index + 1),
                'created_at' => $from->copy()->addHours($index + 1),
                'updated_at' => $from->copy()->addHours($index + 1),
            ]);
        }

        Ticket::create([
            'subject' => 'CSAT outside selected range',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'csat_score' => 1,
            'csat_at' => $to->copy()->addSecond(),
            'created_at' => $from->copy()->addHours(12),
            'updated_at' => $from->copy()->addHours(12),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.support.report', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));

        $response->assertOk()->assertViewHas('csat', function (array $csat): bool {
            return $csat['count'] === 5
                && $csat['avg'] === 2.8
                && $csat['distribution'] === [1 => 1, 2 => 2, 3 => 0, 4 => 1, 5 => 1];
        });
    }


    public function test_report_queries_apply_effective_scope_and_redact_out_of_scope_agent_names(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_blocked' => false,
        ]);
        $hiddenAgent = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Secret Out Of Scope Agent',
            'is_blocked' => false,
        ]);
        $outsideCustomer = User::factory()->create();

        $ownTicket = Ticket::create([
            'user_id' => $admin->id,
            'assigned_to' => $hiddenAgent->id,
            'subject' => 'Own scoped support ticket',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'first_response_at' => now()->subMinutes(30),
            'sla_first_due_at' => now()->subHour(),
            'sla_resolve_due_at' => now()->subHour(),
            'resolved_at' => now(),
            'closed_at' => now(),
            'csat_score' => 5,
            'csat_at' => now(),
        ]);
        $ownTicket->messages()->create([
            'user_id' => $hiddenAgent->id,
            'body' => 'Public response by an out-of-scope agent',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        Ticket::create([
            'user_id' => $outsideCustomer->id,
            'assigned_to' => $hiddenAgent->id,
            'subject' => 'Outside scoped support ticket',
            'status' => 'open',
            'priority' => 'high',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(10),
            'sla_resolve_due_at' => now()->subMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The route intentionally requires global support_admin scope. Exercise the
        // controller boundary directly as a defense-in-depth contract so a future
        // route-scope change cannot silently reintroduce cross-scope aggregates.
        $request = \\Illuminate\\Http\\Request::create('/admin/support-report', 'GET');
        $request->setUserResolver(fn () => $admin);
        $request->attributes->set('farast.scope', 'own');

        $view = app(\\App\\Http\\Controllers\\SupportReportController::class)(
            $request,
            app(\\App\\Services\\SupportSlaService::class),
        );
        $data = $view->getData();
        $names = collect($data['report']['agents'])->pluck('name')->all();
        $hiddenAgentRow = collect($data['report']['agents'])->firstWhere('name', 'کارشناس خارج از محدوده');

        $this->assertSame(1, $data['report']['totals']['tickets']);
        $this->assertSame(1, $data['report']['totals']['closed']);
        $this->assertNotNull($hiddenAgentRow);
        $this->assertSame(1, $hiddenAgentRow['assigned']);
        $this->assertSame(1, $hiddenAgentRow['replies']);
        $this->assertSame(1, $hiddenAgentRow['first_response_total']);
        $this->assertNotContains('Secret Out Of Scope Agent', $names);
        $this->assertSame(1, $data['breached']);
        $this->assertSame(0, $data['backlog']);
        $this->assertSame(0, $data['needsReply']);
        $this->assertSame(1, $data['openInRange']);
        $this->assertSame(1, $data['closedInRange']);
        $this->assertSame(1, $data['csat']['count']);
        $this->assertSame(5.0, $data['csat']['avg']);
        $rendered = view('admin.support-report', $data)->render();
        $this->assertStringNotContainsString('Secret Out Of Scope Agent', $rendered);
        $this->assertStringContainsString('کارشناس خارج از محدوده', $rendered);
    }

    public function test_scoped_support_agent_can_open_and_update_support_sms_settings(): void
    {
        config(['farast_admin.multi_role_console' => true]);
        $agent = $this->supportAgent();

        $this->actingAs($agent)
            ->get(route('admin.support.sms'))
            ->assertOk();

        $this->actingAs($agent)
            ->post(route('admin.support.sms.update'), [
                'sms_enabled' => '1',
                'sms_ticket_reply_enabled' => '1',
                'support_alert_mobile' => '09120000000',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('09120000000', (string) SiteSetting::read('support_alert_mobile'));
        $this->assertTrue(filter_var(SiteSetting::read('sms_ticket_reply_enabled'), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_employee_without_support_permission_cannot_open_support_report_or_sms_settings(): void
    {
        config(['farast_admin.multi_role_console' => true]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)
            ->get(route('admin.support.report'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('admin.support.sms'))
            ->assertForbidden();
    }
}
