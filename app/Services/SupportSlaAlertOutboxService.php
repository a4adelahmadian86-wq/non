<?php

namespace App\Services;

use App\Models\SupportSlaAlertOutbox;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly SupportNotificationService $notifier) {}

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

        DB::table('support_sla_alert_outbox')->insertOrIgnore([
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

        return (int) $stored->id;
    }

    /**
     * Claim due rows in a short transaction, then perform provider calls outside it.
     */
    public function processPending(int $limit = 10): int
    {
        if (! DB::getSchemaBuilder()->hasTable('support_sla_alert_outbox')) {
            return 0;
        }

        $ids = DB::transaction(function () use ($limit): array {
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

            foreach ($rows as $row) {
                $row->forceFill([
                    'status' => 'processing',
                    'attempts' => $row->attempts + 1,
                    'locked_at' => now(),
                    'last_error' => null,
                ])->save();
            }

            return $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        });

        $delivered = 0;
        foreach ($ids as $id) {
            $alert = SupportSlaAlertOutbox::query()->find($id);
            if (! $alert || $alert->status !== 'processing') {
                continue;
            }

            try {
                $ticket = Ticket::query()->with(['assignee', 'user'])->find($alert->ticket_id);
                if ($ticket) {
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
                }

                SupportSlaAlertOutbox::query()->whereKey($id)->where('status', 'processing')->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'locked_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
                $delivered++;
            } catch (Throwable $exception) {
                $attempts = (int) $alert->attempts;
                $delayMinutes = min(60, 2 ** min($attempts, 6));
                $message = mb_substr($exception->getMessage(), 0, 2000);

                SupportSlaAlertOutbox::query()->whereKey($id)->where('status', 'processing')->update([
                    'status' => 'failed',
                    'available_at' => now()->addMinutes($delayMinutes),
                    'locked_at' => null,
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
