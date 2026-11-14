<?php

namespace Tests\\Feature;

use App\\Models\\User;
use App\\Services\\SupportNotificationService;
use Carbon\\Carbon;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;
use Mockery;
use Tests\\TestCase;

class SupportSlaMonitorReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'support.monitor_enabled' => true,
            'support.business_hours_only' => false,
        ]);
    }

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

    public function test_late_first_response_is_still_recorded_as_a_breach(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Late first response',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'first_response_at' => now()->subMinutes(2),
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
    }

    public function test_business_hour_calendar_is_used_for_warning_thresholds(): void
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $now = Carbon::parse('2026-11-15 08:00:00', $timezone);
        Carbon::setTestNow($now);

        try {
            config([
                'support.business_hours_only' => true,
                'support.business_hours.timezone' => $timezone,
                'support.business_hours.start' => 9,
                'support.business_hours.end' => 18,
                'support.business_hours.weekend' => [],
                'support.warn_before_minutes' => 30,
            ]);

            $customer = User::factory()->create();
            $ticketId = DB::table('tickets')->insertGetId([
                'user_id' => $customer->id,
                'subject' => 'Business-hours SLA warning',
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
                'sla_first_due_at' => Carbon::parse('2026-11-15 09:15:00', $timezone),
                'created_at' => $now->copy()->subHour(),
                'updated_at' => $now,
            ]);

            $notifier = Mockery::mock(SupportNotificationService::class);
            $notifier->shouldReceive('slaWarning')->once()->withArgs(
                fn ($ticket, $kind, $minutes): bool => (int) $ticket->id === $ticketId
                    && $kind === 'first_response'
                    && $minutes === 15
            );
            $notifier->shouldNotReceive('slaBreached');
            $this->app->instance(SupportNotificationService::class, $notifier);

            Artisan::call('support:check-sla');

            $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_warned_at'));
        } finally {
            Carbon::setTestNow();
        }
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
        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_warned_at'));
    }

    public function test_notification_failure_for_one_ticket_does_not_stop_the_remaining_queue(): void
    {
        $customer = User::factory()->create();
        $now = now();

        $firstId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'First SLA notification failure',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $now->copy()->subMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $secondId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Second SLA notification',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $now->copy()->subMinutes(5),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $calls = 0;
        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->twice()->andReturnUsing(
            function ($ticket, $kind) use (&$calls): void {
                $calls++;
                if ($calls === 1) {
                    throw new \\RuntimeException('notification provider unavailable');
                }
            }
        );
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull(DB::table('tickets')->where('id', $firstId)->value('sla_first_breached_at'));
        $this->assertNotNull(DB::table('tickets')->where('id', $secondId)->value('sla_first_breached_at'));
    }

}
