<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SupportSlaService
{
    public function applyTargets(Ticket $ticket): void
    {
        $hasFirstDueAt = Schema::hasColumn('tickets', 'sla_first_due_at');
        $hasResolveDueAt = Schema::hasColumn('tickets', 'sla_resolve_due_at');

        if ((! $hasFirstDueAt && ! $hasResolveDueAt) || $ticket->status === 'closed') {
            // Closed-ticket SLA deadlines are historical evidence and must not
            // be rewritten by a later priority edit.
            return;
        }

        $priority = $ticket->priority ?: 'normal';
        $firstMins = (int) config("support.sla_first_response_minutes.{$priority}", 240);
        $resolveMins = (int) config("support.sla_resolution_minutes.{$priority}", 1440);
        $base = $ticket->created_at ? Carbon::parse($ticket->created_at) : now();
        $targets = [];

        // Once a public first response exists, its original deadline is immutable.
        if ($hasFirstDueAt && ! $ticket->first_response_at) {
            $targets['sla_first_due_at'] = $this->addSlaMinutes($base->copy(), $firstMins);
        }

        $resolveDueAt = null;
        if ($hasResolveDueAt) {
            $resolveDueAt = $this->addSlaMinutes($base->copy(), $resolveMins);
            $targets['sla_resolve_due_at'] = $resolveDueAt;
        }

        // A priority change while waiting for the customer must also update
        // the frozen resolution budget, without restarting the paused clock.
        if ($ticket->status === 'pending'
            && $ticket->sla_paused_at
            && $resolveDueAt
            && Schema::hasColumn('tickets', 'sla_resolve_remaining_minutes')) {
            // The clock stopped at sla_paused_at. Recalculate the budget from
            // that instant, not from now(), which may be days later.
            $pauseStartedAt = $ticket->sla_paused_at;
            $remainingAtPause = $this->minutesUntilDeadline($resolveDueAt, $pauseStartedAt);
            $targets['sla_resolve_remaining_minutes'] = max(0, $remainingAtPause);

            if ($remainingAtPause < 0
                && Schema::hasColumn('tickets', 'sla_resolve_breached_at')
                && empty($ticket->sla_resolve_breached_at)) {
                $targets['sla_resolve_breached_at'] = $pauseStartedAt;
            }
        }

        if ($targets !== []) {
            $ticket->forceFill($targets)->saveQuietly();
        }
    }

    public function addSlaMinutes(Carbon $from, int $minutes): Carbon
    {
        if ($minutes <= 0) {
            return $from->copy();
        }

        if (! config('support.business_hours_only', true)) {
            return $from->copy()->addMinutes($minutes);
        }

        return $this->addBusinessMinutes($from, $minutes);
    }

    public function addBusinessMinutes(Carbon $from, int $minutes): Carbon
    {
        $tz = config('support.business_hours.timezone', config('app.timezone', 'Asia/Tehran'));
        $startH = (int) config('support.business_hours.start', 9);
        $endH = (int) config('support.business_hours.end', 18);
        $weekend = config('support.business_hours.weekend', [4, 5]);

        $cursor = $from->copy()->timezone($tz);
        $remaining = $minutes;
        $guard = 0;

        while ($remaining > 0 && $guard < 20000) {
            $guard++;

            if (in_array((int) $cursor->dayOfWeek, $weekend, true)) {
                $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);

                continue;
            }

            $dayStart = $cursor->copy()->setTime($startH, 0, 0);
            $dayEnd = $cursor->copy()->setTime($endH, 0, 0);

            if ($cursor->lt($dayStart)) {
                $cursor = $dayStart->copy();
            }

            if ($cursor->gte($dayEnd)) {
                $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);

                continue;
            }

            $available = (int) $cursor->diffInMinutes($dayEnd);
            if ($available <= 0) {
                $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);

                continue;
            }

            if ($remaining <= $available) {
                return $cursor->copy()->addMinutes($remaining);
            }

            $remaining -= $available;
            $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);
        }

        return $cursor;
    }

    /**
     * Return signed remaining SLA minutes using the configured SLA calendar.
     * Positive means time remains; negative means the deadline was exceeded.
     */
    public function minutesUntilDeadline(Carbon $deadline, ?Carbon $at = null): int
    {
        $at = ($at ?? now())->copy();

        return $deadline->gte($at)
            ? $this->businessMinutesBetween($at, $deadline)
            : -$this->businessMinutesBetween($deadline, $at);
    }

    /** @return array{state:string,label:string,color:string,minutes_left:?int,breached:bool} */
    public function firstResponseStatus(Ticket $ticket): array
    {
        if ($ticket->first_response_at) {
            $due = $ticket->sla_first_due_at;
            $met = ! $due || $ticket->first_response_at->lte($due);

            return [
                'state' => $met ? 'met' : 'breached',
                'label' => $met ? 'پاسخ در SLA' : 'تأخیر در اولین پاسخ',
                'color' => $met ? 'ok' : 'danger',
                'minutes_left' => null,
                'breached' => ! $met,
            ];
        }

        if (! $ticket->sla_first_due_at) {
            return [
                'state' => 'unknown',
                'label' => 'بدون SLA',
                'color' => 'muted',
                'minutes_left' => null,
                'breached' => false,
            ];
        }

        $mins = $this->minutesUntilDeadline($ticket->sla_first_due_at);
        if ($mins < 0) {
            return [
                'state' => 'breached',
                'label' => 'SLA گذشته ('.abs($mins).'د)',
                'color' => 'danger',
                'minutes_left' => $mins,
                'breached' => true,
            ];
        }
        $warnBefore = (int) config('support.warn_before_minutes', 30);
        if ($mins <= $warnBefore) {
            return [
                'state' => 'warning',
                'label' => $mins.'د تا SLA',
                'color' => 'warn',
                'minutes_left' => $mins,
                'breached' => false,
            ];
        }

        return [
            'state' => 'ok',
            'label' => $this->humanMinutes($mins).' تا پاسخ',
            'color' => 'ok',
            'minutes_left' => $mins,
            'breached' => false,
        ];
    }

    /** @return array{state:string,label:string,color:string,breached:bool} */
    public function resolutionStatus(Ticket $ticket): array
    {
        if ($ticket->status === 'pending') {
            $breachedBeforePause = ! empty($ticket->sla_resolve_breached_at);

            return [
                'state' => 'paused',
                'label' => $breachedBeforePause
                    ? 'متوقف؛ مهلت پیش از توقف نقض شده'
                    : 'متوقف (منتظر کاربر)',
                'color' => $breachedBeforePause ? 'danger' : 'muted',
                'breached' => $breachedBeforePause,
            ];
        }

        $resolvedAt = $ticket->resolved_at ?? ($ticket->status === 'closed' ? $ticket->closed_at : null);

        if ($resolvedAt && $ticket->sla_resolve_due_at) {
            $met = $resolvedAt->lte($ticket->sla_resolve_due_at);

            return [
                'state' => $met ? 'met' : 'breached',
                'label' => $met ? 'حل در SLA' : 'تأخیر در حل',
                'color' => $met ? 'ok' : 'danger',
                'breached' => ! $met,
            ];
        }

        if ($ticket->status === 'closed') {
            return [
                'state' => 'unknown',
                'label' => 'بسته شده؛ زمان حل ثبت نشده',
                'color' => 'muted',
                'breached' => false,
            ];
        }

        if (! $ticket->sla_resolve_due_at) {
            return [
                'state' => 'unknown',
                'label' => '—',
                'color' => 'muted',
                'breached' => false,
            ];
        }

        $mins = $this->minutesUntilDeadline($ticket->sla_resolve_due_at);
        if ($mins < 0) {
            return [
                'state' => 'breached',
                'label' => 'مهلت حل گذشته',
                'color' => 'danger',
                'breached' => true,
            ];
        }

        $warnBefore = (int) config('support.warn_before_minutes', 30);

        return [
            'state' => 'ok',
            'label' => $this->humanMinutes($mins).' تا مهلت حل',
            'color' => $mins <= $warnBefore ? 'warn' : 'ok',
            'breached' => false,
        ];
    }

    /**
     * Build the SLA fields required for a status transition.
     * Resolution time is frozen while a ticket waits for the customer.
     *
     * @return array<string, mixed>
     */
    public function statusTransitionAttributes(Ticket $ticket, string $newStatus): array
    {
        if (! Schema::hasColumn('tickets', 'sla_resolve_due_at')
            || ! Schema::hasColumn('tickets', 'sla_paused_at')
            || ! Schema::hasColumn('tickets', 'sla_resolve_remaining_minutes')) {
            return [];
        }

        $oldStatus = (string) $ticket->status;
        if ($oldStatus === $newStatus) {
            return [];
        }

        if ($newStatus === 'pending' && $oldStatus !== 'pending') {
            $dueAt = $ticket->sla_resolve_due_at;
            $pausedAt = now();
            $remaining = $dueAt ? $this->minutesUntilDeadline($dueAt, $pausedAt) : null;
            $attributes = [
                'sla_paused_at' => $pausedAt,
                'sla_resolve_remaining_minutes' => $remaining === null ? null : max(0, $remaining),
            ];

            if ($remaining !== null
                && $remaining < 0
                && Schema::hasColumn('tickets', 'sla_resolve_breached_at')
                && empty($ticket->sla_resolve_breached_at)) {
                $attributes['sla_resolve_breached_at'] = $pausedAt;
            }

            return $attributes;
        }

        if ($oldStatus === 'pending' && $newStatus !== 'pending' && $ticket->sla_paused_at) {
            $remaining = $ticket->sla_resolve_remaining_minutes;

            return [
                'sla_resolve_due_at' => $remaining === null
                    ? $ticket->sla_resolve_due_at
                    : $this->addSlaMinutes(now(), (int) $remaining),
                'sla_paused_at' => null,
                'sla_resolve_remaining_minutes' => null,
            ];
        }

        return [];
    }

    /**
     * Count working minutes in [from, to), using the same calendar as SLA deadlines.
     */
    public function businessMinutesBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lte($from)) {
            return 0;
        }

        if (! config('support.business_hours_only', true)) {
            return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
        }

        $tz = config('support.business_hours.timezone', config('app.timezone', 'Asia/Tehran'));
        $startH = (int) config('support.business_hours.start', 9);
        $endH = (int) config('support.business_hours.end', 18);
        $weekend = config('support.business_hours.weekend', [4, 5]);
        $cursor = $from->copy()->timezone($tz);
        $end = $to->copy()->timezone($tz);
        $minutes = 0;
        $guard = 0;

        while ($cursor->lt($end) && $guard < 20000) {
            $guard++;
            if (in_array((int) $cursor->dayOfWeek, $weekend, true)) {
                $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);
                continue;
            }

            $dayStart = $cursor->copy()->setTime($startH, 0, 0);
            $dayEnd = $cursor->copy()->setTime($endH, 0, 0);
            if ($cursor->lt($dayStart)) {
                $cursor = $dayStart;
            }
            if ($cursor->gte($dayEnd)) {
                $cursor->addDay()->startOfDay()->setTime($startH, 0, 0);
                continue;
            }

            $segmentEnd = $end->lt($dayEnd) ? $end : $dayEnd;
            if ($segmentEnd->gt($cursor)) {
                $minutes += (int) floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            }
            $cursor = $dayEnd->lt($end)
                ? $dayEnd->addDay()->startOfDay()->setTime($startH, 0, 0)
                : $end->copy();
        }

        return $minutes;
    }

    public function markResolved(Ticket $ticket): void
    {
        if (Schema::hasColumn('tickets', 'resolved_at') && empty($ticket->resolved_at)) {
            $ticket->forceFill(['resolved_at' => now()])->saveQuietly();
        }
    }

    /** @return array{agents:array<int,array>,totals:array,from:Carbon,to:Carbon} */
    public function agentReport(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?: now()->subDays(30)->startOfDay();
        $to = $to ?: now()->endOfDay();

        $tickets = Ticket::query()
            ->whereBetween('created_at', [$from, $to])
            ->with(['assignee', 'messages' => fn ($q) => $q->orderBy('created_at')])
            ->get();

        $agents = [];
        $totals = [
            'tickets' => 0,
            'closed' => 0,
            'first_response_met' => 0,
            'first_response_total' => 0,
            'avg_first_response_minutes' => null,
        ];

        $allFirst = [];

        foreach ($tickets as $ticket) {
            $totals['tickets']++;
            if ($ticket->status === 'closed') {
                $totals['closed']++;
            }

            $agentId = $ticket->assigned_to ?: 0;
            $agentName = $ticket->assignee?->name ?? $ticket->assignee?->email ?? 'بدون ارجاع';

            if (! isset($agents[$agentId])) {
                $agents[$agentId] = [
                    'id' => $agentId,
                    'name' => $agentName,
                    'assigned' => 0,
                    'closed' => 0,
                    'replies' => 0,
                    'first_response_met' => 0,
                    'first_response_total' => 0,
                    'first_response_samples' => [],
                ];
            }

            if ($agentId) {
                $agents[$agentId]['assigned']++;
                if ($ticket->status === 'closed') {
                    $agents[$agentId]['closed']++;
                }
            }

            $firstStaff = $ticket->messages->first(function ($m) {
                return $m->isFromStaff() && ! $m->is_internal;
            });
            if ($firstStaff) {
                $mins = $this->businessMinutesBetween($ticket->created_at, $firstStaff->created_at);
                $allFirst[] = $mins;
                $totals['first_response_total']++;
                $met = ! $ticket->sla_first_due_at || $firstStaff->created_at->lte($ticket->sla_first_due_at);
                if ($met) {
                    $totals['first_response_met']++;
                }

                if ($agentId) {
                    $agents[$agentId]['replies']++;
                    $agents[$agentId]['first_response_total']++;
                    $agents[$agentId]['first_response_samples'][] = $mins;
                    if ($met) {
                        $agents[$agentId]['first_response_met']++;
                    }
                }
            }
        }

        foreach ($agents as &$row) {
            $samples = $row['first_response_samples'];
            $row['avg_first_response_minutes'] = count($samples)
                ? (int) round(array_sum($samples) / count($samples))
                : null;
            $row['median_first_response_minutes'] = $this->median($samples);
            $row['sla_rate'] = $row['first_response_total'] > 0
                ? round(100 * $row['first_response_met'] / $row['first_response_total'], 1)
                : null;
            unset($row['first_response_samples']);
        }
        unset($row);

        $totals['avg_first_response_minutes'] = count($allFirst)
            ? (int) round(array_sum($allFirst) / count($allFirst))
            : null;
        $totals['median_first_response_minutes'] = $this->median($allFirst);
        $totals['p90_first_response_minutes'] = $this->percentile($allFirst, 90);
        $totals['sla_rate'] = $totals['first_response_total'] > 0
            ? round(100 * $totals['first_response_met'] / $totals['first_response_total'], 1)
            : null;

        usort($agents, fn ($a, $b) => ($b['assigned'] <=> $a['assigned']));

        return [
            'agents' => array_values($agents),
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
        ];
    }


    /** @param list<int|float> $samples */
    protected function median(array $samples): ?int
    {
        if ($samples === []) {
            return null;
        }
        sort($samples);
        $n = count($samples);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return (int) round($samples[$mid]);
        }

        return (int) round(($samples[$mid - 1] + $samples[$mid]) / 2);
    }

    /** @param list<int|float> $samples */
    protected function percentile(array $samples, float $p): ?int
    {
        if ($samples === []) {
            return null;
        }
        sort($samples);
        $n = count($samples);
        if ($n === 1) {
            return (int) round($samples[0]);
        }
        $rank = ($p / 100) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return (int) round($samples[$lo]);
        }
        $w = $rank - $lo;

        return (int) round($samples[$lo] * (1 - $w) + $samples[$hi] * $w);
    }

    protected function humanMinutes(int $mins): string
    {
        if ($mins < 60) {
            return $mins.'د';
        }
        $h = intdiv($mins, 60);
        $m = $mins % 60;

        return $m ? "{$h}س {$m}د" : "{$h}س";
    }
}
