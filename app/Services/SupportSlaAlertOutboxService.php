<?php

namespace App\Services;

use App\Models\SupportSlaAlertOutbox;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Durable outbox for SLA alerts. Alert intent is committed with the ticket marker;
 * external delivery is performed later without holding the ticket row lock.
 */
class SupportSlaAlertOutboxService
{
    private const MAX_ATTEMPTS = 8;

    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly SupportNotificationService $notifier,
        private readonly AuditEventService $audit,
        private readonly SupportSlaService $sla,
    ) {}

    /**
     * Persist an alert in the caller's ticket transaction.
     *
     * @param array{event:string,minutes:int,column:string} $alert
     */
    public function enqueue(Ticket $ticket, string $kind, array $alert): int
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new RuntimeException('SLA alert intent must be persisted inside the ticket transaction.');
        }

        if (! in_array($kind, ['first_response', 'resolution'], true)
            || ! in_array($alert['event'] ?? null, ['warning', 'breached'], true)) {
            throw new RuntimeException('Unsupported support SLA alert payload.');
        }

        $dueAt = $kind === 'first_response' ? $ticket->sla_first_due_at : $ticket->sla_resolve_due_at;
        $deadlineIdentity = substr(hash('sha256', $dueAt?->format('U.u') ?? 'unknown'), 0, 24);
        $eventIdentity = 'sla:'.$kind.':'.$alert['event'].':'.$deadlineIdentity;
        $idempotencyKey = 'support-sla:'.$ticket->id.':'.$eventIdentity;

        $inserted = DB::table('support_sla_alert_outbox')->insertOrIgnore([
            'ticket_id' => $ticket->id,
            'kind' => $kind,
            'event' => $alert['event'],
            'minutes_left' => (int) $alert['minutes'],
            'event_identity' => $eventIdentity,
            'idempotency_key' => $idempotencyKey,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = SupportSlaAlertOutbox::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $stored
            || (int) $stored->ticket_id !== (int) $ticket->id
            || $stored->kind !== $kind
            || $stored->event !== $alert['event']
            || $stored->event_identity !== $eventIdentity) {
            throw new RuntimeException('SLA alert idempotency key is bound to another event.');
        }

        if ($inserted > 0) {
            $this->audit->record(
                'support.ticket.sla_alert_queued',
                null,
                null,
                'ticket',
                (int) $ticket->id,
                [
                    'outbox_id' => (int) $stored->id,
                    'kind' => $kind,
                    'event' => $alert['event'],
                    'minutes_left' => (int) $alert['minutes'],
                    'event_identity' => $eventIdentity,
                ],
                'support'
            );
        }

        return (int) $stored->id;
    }

    /**
     * Return a reason when an alert no longer represents actionable/current SLA state.
     * Breaches are historical evidence and remain deliverable after close or deadline edits;
     * warnings are suppressed when their deadline or active state has changed.
     */
    private function skipReason(Ticket $ticket, SupportSlaAlertOutbox $alert): ?string
    {
        $deadline = $alert->kind === 'first_response'
            ? $ticket->sla_first_due_at
            : $ticket->sla_resolve_due_at;

        if (! $deadline) {
            return 'SLA deadline no longer exists.';
        }

        if ($alert->event === 'breached') {
            $hasBreachMarker = $alert->kind === 'first_response'
                ? ! empty($ticket->sla_first_breached_at)
                : ! empty($ticket->sla_resolve_breached_at);

            return $hasBreachMarker ? null : 'SLA breach marker is no longer present.';
        }

        if ($alert->event !== 'warning') {
            return 'Unsupported SLA alert event.';
        }

        $deadlineIdentity = substr(hash('sha256', $deadline->format('U.u')), 0, 24);
        $expectedIdentity = 'sla:'.$alert->kind.':warning:'.$deadlineIdentity;
        if (! hash_equals($expectedIdentity, (string) $alert->event_identity)) {
            return 'SLA deadline changed before warning delivery.';
        }

        if ($alert->kind === 'first_response') {
            if ($ticket->first_response_at) {
                return 'First response was recorded before warning delivery.';
            }

            if ($ticket->status === 'closed') {
                return 'Ticket closed before first-response warning delivery.';
            }
        } elseif (in_array($ticket->status, ['pending', 'closed'], true) || $ticket->resolved_at) {
            return 'Resolution SLA is no longer active.';
        }

        $minutesLeft = $this->sla->minutesUntilDeadline($deadline);
        if ($minutesLeft < 0) {
            return 'Warning window expired before delivery.';
        }

        $warnBefore = max(0, (int) config('support.warn_before_minutes', 30));
        if ($minutesLeft > $warnBefore) {
            return 'Ticket is outside the configured warning window.';
        }

        return null;
    }

    private function markSkipped(SupportSlaAlertOutbox $alert, string $leaseToken, string $reason): void
    {
        DB::transaction(function () use ($alert, $leaseToken, $reason): void {
            $changed = SupportSlaAlertOutbox::query()
                ->whereKey($alert->id)
                ->where('status', 'processing')
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => 'skipped',
                    'delivered_at' => null,
                    'locked_at' => null,
                    'lease_token' => null,
                    'last_error' => mb_substr($reason, 0, 2000),
                    'updated_at' => now(),
                ]);

            if ($changed > 0) {
                $this->audit->record(
                    'support.ticket.sla_alert_skipped',
                    null,
                    null,
                    'ticket',
                    (int) $alert->ticket_id,
                    [
                        'outbox_id' => (int) $alert->id,
                        'kind' => $alert->kind,
                        'event' => $alert->event,
                        'event_identity' => $alert->event_identity,
                        'attempts' => (int) $alert->attempts,
                        'reason' => mb_substr($reason, 0, 500),
                    ],
                    'support'
                );
            }
        });
    }

    /**
     * Claim due rows in a short transaction, then perform provider calls outside it.
     */
    public function processPending(int $limit = 10): int
    {
        if (! Schema::hasTable('support_sla_alert_outbox')) {
            return 0;
        }

        $claims = DB::transaction(function () use ($limit): array {
            $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);

            // A worker may die during its final permitted attempt. Close that expired lease
            // explicitly instead of leaving a permanently processing row behind.
            SupportSlaAlertOutbox::query()
                ->where('status', 'processing')
                ->where('attempts', '>=', self::MAX_ATTEMPTS)
                ->where('locked_at', '<=', $staleBefore)
                ->update([
                    'status' => 'failed',
                    'locked_at' => null,
                    'lease_token' => null,
                    'last_error' => 'Retry limit reached after an abandoned delivery lease.',
                    'updated_at' => now(),
                ]);

            $rows = SupportSlaAlertOutbox::query()
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($query) use ($staleBefore): void {
                    $query->where(function ($pending): void {
                        $pending->whereIn('status', ['pending', 'failed'])
                            ->where('available_at', '<=', now());
                    })->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', 'processing')
                            ->where('locked_at', '<=', $staleBefore);
                    });
                })
                ->orderBy('id')
                ->limit(max(1, min($limit, 50)))
                ->lockForUpdate()
                ->get();

            $claims = [];
            foreach ($rows as $row) {
                $leaseToken = (string) Str::uuid();
                $row->forceFill([
                    'status' => 'processing',
                    'attempts' => $row->attempts + 1,
                    'locked_at' => now(),
                    'lease_token' => $leaseToken,
                    'last_error' => null,
                ])->save();

                $claims[] = ['id' => (int) $row->id, 'lease_token' => $leaseToken];
            }

            return $claims;
        });

        $delivered = 0;
        foreach ($claims as $claim) {
            $id = $claim['id'];
            $leaseToken = $claim['lease_token'];
            // Batch claims are acquired together, but delivery is sequential. Refresh
            // this row's lease immediately before its provider calls so later rows do
            // not expire while earlier alerts in the same batch are being delivered.
            // The token check fences out a worker whose lease has already been reclaimed.
            $alert = DB::transaction(function () use ($id, $leaseToken): ?SupportSlaAlertOutbox {
                $claimed = SupportSlaAlertOutbox::query()
                    ->whereKey($id)
                    ->where('status', 'processing')
                    ->where('lease_token', $leaseToken)
                    ->lockForUpdate()
                    ->first();

                if (! $claimed) {
                    return null;
                }

                $claimed->forceFill(['locked_at' => now()])->save();

                return $claimed;
            });

            if (! $alert) {
                continue;
            }

            try {
                $ticket = Ticket::query()->with(['assignee', 'user'])->find($alert->ticket_id);
                if (! $ticket) {
                    $this->markSkipped($alert, $leaseToken, 'Ticket no longer exists.');
                    continue;
                }

                $skipReason = $this->skipReason($ticket, $alert);
                if ($skipReason !== null) {
                    $this->markSkipped($alert, $leaseToken, $skipReason);
                    continue;
                }

                if ($alert->event === 'breached') {
                    $this->notifier->slaBreached($ticket, $alert->kind, $alert->event_identity);
                } else {
                    $this->notifier->slaWarning(
                        $ticket,
                        $alert->kind,
                        (int) $alert->minutes_left,
                        $alert->event_identity,
                    );
                }

                // Commit the outbox completion and canonical audit event together.
                // If audit append fails, this transaction rolls back and the alert
                // remains retryable; channel-level idempotency prevents duplicate effects.
                $updated = DB::transaction(function () use ($id, $alert, $leaseToken): int {
                    $changed = SupportSlaAlertOutbox::query()
                        ->whereKey($id)
                        ->where('status', 'processing')
                        ->where('lease_token', $leaseToken)
                        ->update([
                            'status' => 'delivered',
                            'delivered_at' => now(),
                            'locked_at' => null,
                            'lease_token' => null,
                            'last_error' => null,
                            'updated_at' => now(),
                        ]);

                    if ($changed > 0) {
                        $this->audit->record(
                            'support.ticket.sla_alert_dispatched',
                            null,
                            null,
                            'ticket',
                            (int) $alert->ticket_id,
                            [
                                'outbox_id' => (int) $id,
                                'kind' => $alert->kind,
                                'event' => $alert->event,
                                'minutes_left' => $alert->minutes_left,
                                'attempts' => (int) $alert->attempts,
                                'event_identity' => $alert->event_identity,
                            ],
                            'support'
                        );
                    }

                    return $changed;
                });

                if ($updated > 0) {
                    $delivered++;
                }
            } catch (Throwable $exception) {
                $attempts = (int) $alert->attempts;
                $delayMinutes = min(60, 2 ** min($attempts, 6));
                $message = mb_substr($exception->getMessage(), 0, 2000);

                SupportSlaAlertOutbox::query()->whereKey($id)->where('status', 'processing')->where('lease_token', $leaseToken)->update([
                    'status' => 'failed',
                    'available_at' => now()->addMinutes($delayMinutes),
                    'locked_at' => null,
                    'lease_token' => null,
                    'last_error' => $message,
                    'updated_at' => now(),
                ]);

                Log::warning('farast.support.sla_outbox_delivery_failed', [
                    'outbox_id' => $id,
                    'ticket_id' => $alert->ticket_id,
                    'attempt' => $attempts,
                    'exception_class' => get_class($exception),
                ]);
                report($exception);
            }
        }

        return $delivered;
    }
}
