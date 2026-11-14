<?php

namespace Tests\Feature;

use App\Console\Commands\CheckSupportSlaCommand;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Mockery;
use Tests\TestCase;

class SupportSlaBusinessMinuteTest extends TestCase
{
    use RefreshDatabase;

    private function runSlaCommandDirectly(): void
    {
        $command = app(CheckSupportSlaCommand::class);
        $command->setLaravel($this->app);
        $command->run(new ArrayInput([]), new BufferedOutput());
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Tehran',
            'support.business_hours_only' => true,
            'support.business_hours.timezone' => 'Asia/Tehran',
            'support.business_hours.start' => 9,
            'support.business_hours.end' => 18,
            'support.business_hours.weekend' => [4, 5],
            'support.business_hours.holidays' => [],
            'support.warn_before_minutes' => 30,
        ]);
    }

    public function test_sla_countdown_uses_business_minutes_across_the_close_of_business(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran'));
        $sla = app(SupportSlaService::class);
        $deadline = $sla->addSlaMinutes(now(), 30);

        $this->assertSame('2026-11-18 09:15:00', $deadline->format('Y-m-d H:i:s'));
        $this->assertSame(30, $sla->minutesUntilDeadline($deadline));

        $ticket = new Ticket([
            'status' => 'open',
            'sla_first_due_at' => $deadline,
        ]);

        $status = $sla->firstResponseStatus($ticket);

        $this->assertSame('warning', $status['state']);
        $this->assertSame(30, $status['minutes_left']);
    }

    public function test_sla_deadline_and_countdown_skip_configured_business_holidays(): void
    {
        config(['support.business_hours.holidays' => ['2026-11-18']]);
        $this->travelTo(Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran'));

        $sla = app(SupportSlaService::class);
        $deadline = $sla->addSlaMinutes(now(), 30);

        $this->assertSame('2026-11-19 09:15:00', $deadline->format('Y-m-d H:i:s'));
        $this->assertSame(30, $sla->minutesUntilDeadline($deadline));
        $this->assertSame(-30, $sla->minutesUntilDeadline(
            $deadline->copy()->subBusinessDay(),
            Carbon::parse('2026-11-19 09:45:00', 'Asia/Tehran')
        ));
    }

    public function test_agent_report_measures_first_response_in_business_minutes(): void
    {
        $createdAt = Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran');
        $this->travelTo($createdAt);

        $agent = User::factory()->create();
        $ticket = Ticket::create([
            'subject' => 'Business-time agent metric',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'assigned_to' => $agent->id,
        ]);

        $this->travelTo(Carbon::parse('2026-11-18 09:15:00', 'Asia/Tehran'));
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $agent->id,
            'body' => 'First public response',
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $report = app(SupportSlaService::class)->agentReport(
            $createdAt->copy()->subMinute(),
            now()->copy()->addMinute()
        );

        $this->assertSame(30, $report['totals']['avg_first_response_minutes']);
        $this->assertSame(30, $report['agents'][0]['avg_first_response_minutes']);
    }

    public function test_sla_monitor_sends_warning_when_business_time_reaches_threshold(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran'));
        $sla = app(SupportSlaService::class);
        $deadline = $sla->addSlaMinutes(now(), 30);

        $ticket = Ticket::create([
            'subject' => 'Business-hour warning',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $deadline,
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaWarning')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'first_response', 30);
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull($ticket->fresh()->sla_first_warned_at);
    }

    public function test_failed_sla_notification_does_not_mark_the_event_as_sent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran'));
        $deadline = app(SupportSlaService::class)->addSlaMinutes(now(), 30);
        $ticket = Ticket::create([
            'subject' => 'Retry SLA warning after provider failure',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $deadline,
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaWarning')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'first_response', 30)
            ->andThrow(new \RuntimeException('temporary notification failure'));
        $this->app->instance(SupportNotificationService::class, $notifier);

        try {
            $this->runSlaCommandDirectly();
            $this->fail('The notifier failure should be surfaced to the command runner.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('temporary notification failure', $exception->getMessage());
        }

        $this->assertNull($ticket->fresh()->sla_first_warned_at);
    }

    public function test_sla_monitor_does_not_starve_tickets_after_the_first_batch(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 17:45:00', 'Asia/Tehran'));
        $sla = app(SupportSlaService::class);
        $deadline = $sla->addSlaMinutes(now(), 30);

        for ($i = 1; $i <= 500; $i++) {
            Ticket::create([
                'subject' => 'No SLA '.$i,
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
            ]);
        }

        $lastTicket = Ticket::create([
            'subject' => 'Ticket beyond first batch',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $deadline,
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaWarning')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($lastTicket)), 'first_response', 30);
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull($lastTicket->fresh()->sla_first_warned_at);
    }

    public function test_late_closed_ticket_remains_in_historical_sla_breach_scope(): void
    {
        $deadline = now()->subHour();
        $ticket = Ticket::create([
            'subject' => 'Closed after SLA deadline',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => $deadline,
            'closed_at' => now(),
        ]);

        $this->assertTrue(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
        $this->assertSame('breached', app(SupportSlaService::class)->resolutionStatus($ticket)['state']);
    }

    public function test_closed_ticket_without_resolution_timestamp_is_not_assumed_to_have_met_sla(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Missing resolution timestamp',
            'status' => 'closed',
            'priority' => 'normal',
            'department' => 'support',
            'sla_resolve_due_at' => now()->subHour(),
        ]);

        $status = app(SupportSlaService::class)->resolutionStatus($ticket);

        $this->assertSame('unknown', $status['state']);
        $this->assertFalse(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
    }

    public function test_late_first_response_remains_a_historical_sla_breach(): void
    {
        $deadline = now()->subMinutes(20);
        $ticket = Ticket::create([
            'subject' => 'Late first response history',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $deadline,
            'first_response_at' => $deadline->copy()->addMinutes(5),
        ]);

        $this->assertTrue(Ticket::query()->slaBreached()->whereKey($ticket->id)->exists());
        $this->assertSame('breached', app(SupportSlaService::class)->firstResponseStatus($ticket)['state']);
    }

    public function test_sla_monitor_notifies_for_a_late_first_response_already_sent(): void
    {
        $deadline = now()->subMinutes(20);
        $ticket = Ticket::create([
            'subject' => 'Notify late first response',
            'status' => 'answered',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => $deadline,
            'first_response_at' => $deadline->copy()->addMinutes(5),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')
            ->once()
            ->with(Mockery::on(fn (Ticket $candidate) => $candidate->is($ticket)), 'first_response');
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull($ticket->fresh()->sla_first_breached_at);
    }

    public function test_overdue_countdown_reports_only_elapsed_business_minutes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 09:30:00', 'Asia/Tehran'));
        $sla = app(SupportSlaService::class);
        $deadline = Carbon::parse('2026-11-18 17:30:00', 'Asia/Tehran');

        $this->assertSame(-60, $sla->minutesUntilDeadline($deadline));
    }
}
