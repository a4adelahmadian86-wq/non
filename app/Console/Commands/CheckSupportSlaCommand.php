<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\SupportNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckSupportSlaCommand extends Command
{
    protected $signature = 'support:check-sla {--dry-run : فقط گزارش بدون ارسال اعلان}';

    protected $description = 'پایش SLA تیکت‌ها و ارسال هشدار نزدیک‌به‌نقض / نقض';

    public function handle(SupportNotificationService $notifier): int
    {
        if (! config('support.monitor_enabled', true)) {
            $this->info('پایش SLA غیرفعال است.');

            return self::SUCCESS;
        }

        $requiredColumns = [
            'first_response_at',
            'sla_first_due_at',
            'sla_first_breached_at',
            'sla_first_warned_at',
            'sla_resolve_due_at',
            'sla_resolve_breached_at',
            'sla_resolve_warned_at',
        ];
        if (! Schema::hasTable('tickets')
            || collect($requiredColumns)->contains(fn (string $column): bool => ! Schema::hasColumn('tickets', $column))) {
            $this->warn('ستون‌های موردنیاز پایش SLA موجود نیستند. migrate را اجرا کنید.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $warnBefore = max(0, (int) config('support.warn_before_minutes', 30));
        $stats = ['warned_first' => 0, 'breached_first' => 0, 'warned_resolve' => 0, 'breached_resolve' => 0];

        // Cursor through the entire active queue. A fixed first-page limit
        // permanently starves tickets whose IDs are greater than that limit.
        Ticket::query()
            ->openQueue()
            ->with(['assignee', 'user'])
            ->chunkById(200, function ($tickets) use ($dry, $warnBefore, $notifier, &$stats): void {
                foreach ($tickets as $snapshot) {
                    foreach (['first_response', 'resolution'] as $kind) {
                        $alert = $dry
                            ? $this->pendingAlert($snapshot, $kind, $warnBefore)
                            : $this->claimAlert((int) $snapshot->id, $kind, $warnBefore);

                        if (! $alert) {
                            continue;
                        }

                        $ticket = $alert['ticket'] ?? $snapshot;
                        $event = $alert['event'];
                        $minutes = (int) $alert['minutes'];
                        $statKey = match ([$kind, $event]) {
                            ['first_response', 'warning'] => 'warned_first',
                            ['first_response', 'breached'] => 'breached_first',
                            ['resolution', 'warning'] => 'warned_resolve',
                            default => 'breached_resolve',
                        };
                        $stats[$statKey]++;

                        $label = $kind === 'resolution' ? 'resolve' : 'first';
                        $this->line($event === 'breached'
                            ? "BREACH {$label} #{$ticket->id} {$ticket->displayCode()}"
                            : "WARN {$label} #{$ticket->id} ({$minutes}m left)");

                        if ($dry) {
                            continue;
                        }

                        try {
                            if ($event === 'breached') {
                                $notifier->slaBreached($ticket, $kind);
                            } else {
                                $notifier->slaWarning($ticket, $kind, $minutes);
                            }
                        } catch (Throwable $exception) {
                            // Keep the claimed marker to avoid duplicate partial delivery.
                            // Channel-level delivery failures are logged with ticket context.
                            Log::error('farast.support.sla_notification_failed', [
                                'ticket_id' => $ticket->id,
                                'kind' => $kind,
                                'event' => $event,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                }
            });

        $this->info(sprintf(
            'SLA check done. first(warn=%d,breach=%d) resolve(warn=%d,breach=%d)%s',
            $stats['warned_first'],
            $stats['breached_first'],
            $stats['warned_resolve'],
            $stats['breached_resolve'],
            $dry ? ' [dry-run]' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Determine an unclaimed alert from the current ticket snapshot.
     *
     * @return array{event:string,minutes:int,column:string}|null
     */
    private function pendingAlert(Ticket $ticket, string $kind, int $warnBefore): ?array
    {
        if ($ticket->status === 'closed') {
            return null;
        }

        if ($kind === 'first_response') {
            if ($ticket->first_response_at || ! $ticket->sla_first_due_at) {
                return null;
            }

            $minutes = (int) now()->diffInMinutes($ticket->sla_first_due_at, false);
            if ($minutes < 0
                && empty($ticket->sla_first_breached_at)) {
                return ['event' => 'breached', 'minutes' => $minutes, 'column' => 'sla_first_breached_at'];
            }

            if ($minutes >= 0 && $minutes <= $warnBefore
                && empty($ticket->sla_first_warned_at)) {
                return ['event' => 'warning', 'minutes' => $minutes, 'column' => 'sla_first_warned_at'];
            }

            return null;
        }

        // Waiting for the customer pauses only the resolution clock.
        if ($ticket->status === 'pending' || ! $ticket->sla_resolve_due_at) {
            return null;
        }

        $minutes = (int) now()->diffInMinutes($ticket->sla_resolve_due_at, false);
        if ($minutes < 0
            && empty($ticket->sla_resolve_breached_at)) {
            return ['event' => 'breached', 'minutes' => $minutes, 'column' => 'sla_resolve_breached_at'];
        }

        if ($minutes >= 0 && $minutes <= $warnBefore
            && empty($ticket->sla_resolve_warned_at)) {
            return ['event' => 'warning', 'minutes' => $minutes, 'column' => 'sla_resolve_warned_at'];
        }

        return null;
    }

    /**
     * Atomically claim one alert. Concurrent scheduler/manual runs serialize
     * on the ticket row, so only one process can dispatch each alert marker.
     *
     * @return array{ticket:Ticket,event:string,minutes:int}|null
     */
    private function claimAlert(int $ticketId, string $kind, int $warnBefore): ?array
    {
        return DB::transaction(function () use ($ticketId, $kind, $warnBefore): ?array {
            $ticket = Ticket::query()->lockForUpdate()->find($ticketId);
            if (! $ticket) {
                return null;
            }

            $alert = $this->pendingAlert($ticket, $kind, $warnBefore);
            if (! $alert) {
                return null;
            }

            $ticket->forceFill([$alert['column'] => now()])->saveQuietly();

            return [
                'ticket' => $ticket->fresh(['assignee', 'user']),
                'event' => $alert['event'],
                'minutes' => $alert['minutes'],
            ];
        });
    }
}
