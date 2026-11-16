<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditEventService;
use App\Services\SupportNotificationService;
use App\Services\SupportSlaService;
use App\Services\SupportSlaAlertOutboxService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

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
        $notifier->shouldNotReceive('slaBreached');
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');
        Artisan::call('support:check-sla');

        $this->assertSame(501, DB::table('tickets')->whereNotNull('sla_first_breached_at')->count());
        $this->assertSame(501, DB::table('support_sla_alert_outbox')->where('kind', 'first_response')->where('event', 'breached')->count());
        $this->assertSame(501, DB::table('farast_audit_events')->where('event_type', 'support.ticket.sla_alert_queued')->count());
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
        $notifier->shouldNotReceive('slaBreached');
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_breached_at'));
        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_resolve_breached_at'));
        $this->assertDatabaseHas('support_sla_alert_outbox', ['ticket_id' => $ticketId, 'kind' => 'first_response', 'event' => 'breached']);
        $this->assertDatabaseMissing('support_sla_alert_outbox', ['ticket_id' => $ticketId, 'kind' => 'resolution']);
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
        $notifier->shouldNotReceive('slaBreached');
        $notifier->shouldNotReceive('slaWarning');
        $this->app->instance(SupportNotificationService::class, $notifier);

        Artisan::call('support:check-sla');

        $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_breached_at'));
        $this->assertDatabaseHas('support_sla_alert_outbox', ['ticket_id' => $ticketId, 'kind' => 'first_response', 'event' => 'breached']);
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
            $notifier->shouldNotReceive('slaWarning');
            $notifier->shouldNotReceive('slaBreached');
            $this->app->instance(SupportNotificationService::class, $notifier);

            Artisan::call('support:check-sla');

            $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_warned_at'));
            $this->assertDatabaseHas('support_sla_alert_outbox', ['ticket_id' => $ticketId, 'kind' => 'first_response', 'event' => 'warning', 'minutes_left' => 15]);
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
        $this->assertDatabaseMissing('support_sla_alert_outbox', ['ticket_id' => $ticketId]);
    }


    public function test_alert_delivery_is_outside_ticket_transaction_and_failed_attempt_is_retried(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Retryable SLA alert',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')->where('ticket_id', $ticketId)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('pending', $outbox->status);
        $this->assertSame(0, (int) $outbox->attempts);

        $baselineTransactionLevel = DB::transactionLevel();
        $failedNotifier = Mockery::mock(SupportNotificationService::class);
        $failedNotifier->shouldReceive('slaBreached')->once()->withArgs(
            function ($ticket, $kind, $identity) use ($ticketId, $baselineTransactionLevel): bool {
                $this->assertSame($baselineTransactionLevel, DB::transactionLevel(), 'Delivery must not retain an additional ticket-claim transaction.');
                return (int) $ticket->id === $ticketId && $kind === 'first_response' && str_contains($identity, 'sla:first_response:breached');
            }
        )->andThrow(new \RuntimeException('temporary provider failure'));
        $this->app->instance(SupportNotificationService::class, $failedNotifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', ['id' => $outbox->id, 'status' => 'failed', 'attempts' => 1, 'lease_token' => null]);

        DB::table('support_sla_alert_outbox')->where('id', $outbox->id)->update(['available_at' => now()->subMinute()]);
        $successfulNotifier = Mockery::mock(SupportNotificationService::class);
        $successfulNotifier->shouldReceive('slaBreached')->once()->withArgs(
            fn ($ticket, $kind, $identity): bool => (int) $ticket->id === $ticketId
                && $kind === 'first_response'
                && $identity === $outbox->event_identity
        );
        $this->app->instance(SupportNotificationService::class, $successfulNotifier);

        $this->assertSame(1, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'delivered',
            'attempts' => 2,
            'lease_token' => null,
        ]);
        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());
    }



    public function test_audit_append_failure_rolls_back_sla_marker_and_outbox_intent(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'SLA audit rollback',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        $audit = Mockery::mock(AuditEventService::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit ledger unavailable'));
        $this->app->instance(AuditEventService::class, $audit);

        try {
            Artisan::call('support:check-sla');
            $this->fail('SLA marker and outbox intent must roll back if the canonical audit append fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit ledger unavailable', $exception->getMessage());
        }

        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_breached_at'));
        $this->assertDatabaseMissing('support_sla_alert_outbox', ['ticket_id' => $ticketId]);
    }

    public function test_outbox_enqueue_rolls_back_with_the_ticket_transaction(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Transactional SLA outbox',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        $ticket = Ticket::query()->findOrFail($ticketId);
        $notifier = Mockery::mock(SupportNotificationService::class);
        $this->app->instance(SupportNotificationService::class, $notifier);
        $outbox = app(SupportSlaAlertOutboxService::class);

        try {
            DB::transaction(function () use ($outbox, $ticket): void {
                $outbox->enqueue($ticket, 'first_response', [
                    'event' => 'breached',
                    'minutes' => -5,
                    'column' => 'sla_first_breached_at',
                ]);
                throw new \RuntimeException('rollback outbox with ticket marker');
            });
            $this->fail('The surrounding ticket transaction should roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback outbox with ticket marker', $exception->getMessage());
        }

        $this->assertDatabaseMissing('support_sla_alert_outbox', ['ticket_id' => $ticketId]);
        $this->assertDatabaseMissing('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticketId,
            'event_type' => 'support.ticket.sla_alert_queued',
        ]);
    }


    public function test_first_response_warning_is_skipped_if_a_public_response_arrives_before_delivery(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Stale first-response warning',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->addMinutes(10),
            'sla_resolve_due_at' => now()->addHours(3),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')
            ->where('ticket_id', $ticketId)
            ->where('kind', 'first_response')
            ->where('event', 'warning')
            ->first();
        $this->assertNotNull($outbox);

        DB::table('tickets')->where('id', $ticketId)->update([
            'status' => 'answered',
            'first_response_at' => now(),
            'updated_at' => now(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldNotReceive('slaWarning');
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'skipped',
            'lease_token' => null,
        ]);
        $this->assertDatabaseHas('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticketId,
            'event_type' => 'support.ticket.sla_alert_skipped',
        ]);
    }

    public function test_resolution_warning_is_skipped_after_ticket_enters_customer_wait_state(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Paused resolution warning',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->addHours(3),
            'sla_resolve_due_at' => now()->addMinutes(10),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')
            ->where('ticket_id', $ticketId)
            ->where('kind', 'resolution')
            ->where('event', 'warning')
            ->first();
        $this->assertNotNull($outbox);

        DB::table('tickets')->where('id', $ticketId)->update([
            'status' => 'pending',
            'sla_paused_at' => now(),
            'sla_resolve_remaining_minutes' => 10,
            'updated_at' => now(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldNotReceive('slaWarning');
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'skipped',
            'lease_token' => null,
        ]);
    }

    public function test_historical_breach_alert_remains_deliverable_after_ticket_is_closed(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Historical SLA breach',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(10),
            'sla_resolve_due_at' => now()->addHours(3),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')
            ->where('ticket_id', $ticketId)
            ->where('kind', 'first_response')
            ->where('event', 'breached')
            ->first();
        $this->assertNotNull($outbox);

        DB::table('tickets')->where('id', $ticketId)->update([
            'status' => 'closed',
            'closed_at' => now(),
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->once()->withArgs(
            fn ($ticket, $kind, $identity): bool => (int) $ticket->id === $ticketId
                && $kind === 'first_response'
                && $identity === $outbox->event_identity
        );
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(1, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'delivered',
        ]);
    }

    public function test_priority_deadline_change_resets_warning_marker_and_fences_old_outbox_event(): void
    {
        config([
            'support.sla_first_response_minutes.normal' => 20,
            'support.sla_first_response_minutes.high' => 60,
            'support.business_hours_only' => false,
        ]);
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Priority changes SLA warning window',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->addMinutes(20),
            'sla_resolve_due_at' => now()->addHours(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')
            ->where('ticket_id', $ticketId)
            ->where('kind', 'first_response')
            ->where('event', 'warning')
            ->first();
        $this->assertNotNull($outbox);
        $this->assertNotNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_warned_at'));

        $ticket = Ticket::query()->findOrFail($ticketId);
        $ticket->priority = 'high';
        $ticket->save();
        app(SupportSlaService::class)->applyTargets($ticket->fresh());

        $this->assertNull(DB::table('tickets')->where('id', $ticketId)->value('sla_first_warned_at'));

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldNotReceive('slaWarning');
        $notifier->shouldNotReceive('slaBreached');
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());
        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'skipped',
        ]);
    }

    public function test_resuming_a_paused_ticket_starts_a_new_resolution_warning_window(): void
    {
        $ticket = new Ticket();
        $ticket->status = 'pending';
        $ticket->sla_resolve_due_at = now()->addMinutes(20);
        $ticket->sla_paused_at = now()->subMinutes(10);
        $ticket->sla_resolve_remaining_minutes = 10;
        $ticket->sla_resolve_warned_at = now()->subMinutes(2);

        $attributes = app(SupportSlaService::class)->statusTransitionAttributes($ticket, 'open');

        $this->assertArrayHasKey('sla_resolve_warned_at', $attributes);
        $this->assertNull($attributes['sla_resolve_warned_at']);
        $this->assertArrayHasKey('sla_resolve_due_at', $attributes);
    }

    public function test_expired_worker_cannot_mark_alert_delivered_after_another_worker_reclaims_lease(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Fenced SLA delivery',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')->where('ticket_id', $ticketId)->first();
        $replacementToken = '00000000-0000-4000-8000-000000000002';

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->once()->withArgs(
            function ($ticket, $kind, $identity) use ($ticketId, $outbox, $replacementToken): bool {
                DB::table('support_sla_alert_outbox')->where('id', $outbox->id)->update([
                    'lease_token' => $replacementToken,
                    'locked_at' => now(),
                ]);

                return (int) $ticket->id === $ticketId
                    && $kind === 'first_response'
                    && $identity === $outbox->event_identity;
            }
        );
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());

        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'processing',
            'lease_token' => $replacementToken,
        ]);
        $this->assertDatabaseMissing('farast_audit_events', [
            'aggregate_type' => 'ticket',
            'aggregate_id' => $ticketId,
            'event_type' => 'support.ticket.sla_alert_dispatched',
        ]);
    }

    public function test_expired_worker_cannot_fail_alert_after_another_worker_reclaims_lease(): void
    {
        $customer = User::factory()->create();
        $ticketId = DB::table('tickets')->insertGetId([
            'user_id' => $customer->id,
            'subject' => 'Fenced SLA failure',
            'status' => 'open',
            'priority' => 'normal',
            'department' => 'support',
            'sla_first_due_at' => now()->subMinutes(5),
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);

        Artisan::call('support:check-sla');
        $outbox = DB::table('support_sla_alert_outbox')->where('ticket_id', $ticketId)->first();
        $replacementToken = '00000000-0000-4000-8000-000000000003';

        $notifier = Mockery::mock(SupportNotificationService::class);
        $notifier->shouldReceive('slaBreached')->once()->andReturnUsing(
            function ($ticket, $kind, $identity) use ($outbox, $replacementToken): void {
                DB::table('support_sla_alert_outbox')->where('id', $outbox->id)->update([
                    'lease_token' => $replacementToken,
                    'locked_at' => now(),
                ]);

                throw new \RuntimeException('stale worker provider failure');
            }
        );
        $this->app->instance(SupportNotificationService::class, $notifier);

        $this->assertSame(0, app(SupportSlaAlertOutboxService::class)->processPending());

        $this->assertDatabaseHas('support_sla_alert_outbox', [
            'id' => $outbox->id,
            'status' => 'processing',
            'lease_token' => $replacementToken,
        ]);
    }

    public function test_batch_delivery_refreshes_each_lease_when_earlier_alerts_take_longer_than_the_lease_window(): void
    {
        $base = Carbon::parse('2026-11-16 10:00:00', config('app.timezone'));
        Carbon::setTestNow($base);

        try {
            $customer = User::factory()->create();
            $ticketIds = [];

            foreach ([1, 2] as $number) {
                $ticketIds[] = DB::table('tickets')->insertGetId([
                    'user_id' => $customer->id,
                    'subject' => 'Sequential SLA lease '.$number,
                    'status' => 'open',
                    'priority' => 'normal',
                    'department' => 'support',
                    'sla_first_due_at' => $base->copy()->subMinutes(5),
                    'created_at' => $base->copy()->subHour(),
                    'updated_at' => $base,
                ]);
            }

            Artisan::call('support:check-sla');

            $secondOutbox = DB::table('support_sla_alert_outbox')
                ->where('ticket_id', $ticketIds[1])
                ->first();
            $this->assertNotNull($secondOutbox);

            $notifier = Mockery::mock(SupportNotificationService::class);
            $notifier->shouldReceive('slaBreached')->twice()->andReturnUsing(
                function ($ticket, $kind, $identity) use ($ticketIds, $secondOutbox, $base): void {
                    if ((int) $ticket->id === $ticketIds[0]) {
                        // Simulate a slow first delivery while the second row is waiting
                        // in the same batch with a lease timestamp from the initial claim.
                        Carbon::setTestNow($base->copy()->addMinutes(16));

                        return;
                    }

                    $this->assertSame($ticketIds[1], (int) $ticket->id);
                    $this->assertSame('first_response', $kind);
                    $this->assertSame($secondOutbox->event_identity, $identity);

                    $lockedAt = Carbon::parse(
                        DB::table('support_sla_alert_outbox')->where('id', $secondOutbox->id)->value('locked_at')
                    );

                    $this->assertSame(
                        Carbon::now()->timestamp,
                        $lockedAt->timestamp,
                        'A row must receive a fresh lease when its own delivery starts, not when the whole batch was claimed.'
                    );
                }
            );
            $this->app->instance(SupportNotificationService::class, $notifier);

            $this->assertSame(2, app(SupportSlaAlertOutboxService::class)->processPending());

            $this->assertDatabaseHas('support_sla_alert_outbox', [
                'id' => $secondOutbox->id,
                'status' => 'delivered',
                'lease_token' => null,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

}
