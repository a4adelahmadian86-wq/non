<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
