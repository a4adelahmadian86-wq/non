<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SupportNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SupportSlaMonitorReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_processes_more_than_five_hundred_tickets_and_does_not_repeat_claimed_alerts(): void
    {
        $customer = User::factory()->create();
        $now = now();
        $rows = [];

        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [
                'user_id' => $customer->id,
                'subject' => 'SLA monitor ticket '.$i,
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
                'sla_first_due_at' => $now->copy()->subMinutes(5),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 100) {
                DB::table('tickets')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('tickets')->insert($rows);
        }

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->times(501)->withArgs(
            fn ($ticket, $kind): bool => $kind === 'first_response'
        );
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');
        Artisan::call('support:check-sla');

        $this->assertSame(501, DB::table('tickets')->whereNotNull('sla_first_breached_at')->count());
    }

    public function test_pending_ticket_keeps_first_response_monitoring_but_pauses_resolution_monitoring(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Pending SLA monitor',
            'status' => 'pending',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'sla_resolve_due_at' => now()->subMinutes(5),
            'sla_paused_at' => now()->subMinutes(10),
            'sla_resolve_remaining_minutes' => 30,
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->once()->withArgs(
            fn ($ticket, $kind): bool => (int) $ticket->id === $ticketId && $kind === 'first_response'
        );
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_breached_at'));
        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_resolve_breached_at'));
    }

    public function test_dry_run_reports_due_alerts_without_claiming_them_or_sending_notifications(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Dry run SLA monitor',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldNotReceive('slaBreached');
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla', ['--dry-run' => true]);

        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_breached_at'));
    }
}
