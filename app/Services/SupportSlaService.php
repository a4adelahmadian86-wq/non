<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
            $firstDueAt = $this->addSlaMinutes($base->copy(), $firstMins);
            $targets['sla_first_due_at'] = $firstDueAt;

            if (Schema::hasColumn('tickets', 'sla_first_warned_at')
                && empty($ticket->sla_first_breached_at)
                && (! $ticket->sla_first_due_at
                    || ! Carbon::parse($ticket->sla_first_due_at)->equalTo($firstDueAt))) {
                // A new deadline is a new warning window. Old pending outbox
                // rows are fenced by their deadline identity and this marker
                // permits the monitor to enqueue the new warning when relevant.
                $targets['sla_first_warned_at'] = null;
            }
        }

        $resolveDueAt = null;
        if ($hasResolveDueAt) {
            $resolveDueAt = $this->addSlaMinutes($base->copy(), $resolveMins);
            $targets['sla_resolve_due_at'] = $resolveDueAt;

            if (Schema::hasColumn('tickets', 'sla_resolve_warned_at')
                && empty($ticket->sla_resolve_breached_at)
                && (! $ticket->sla_resolve_due_at
                    || ! Carbon::parse($ticket->sla_resolve_due_at)->equalTo($resolveDueAt))) {
                $targets['sla_resolve_warned_at'] = null;
            }
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

            if (! $this->isBusinessDay($cursor, $weekend)) {
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
        $hasResolutionBreach = ! empty($ticket->sla_resolve_breached_at);

        if ($ticket->status === 'pending') {
            return [
                'state' => 'paused',
                'label' => $hasResolutionBreach
                    ? 'متوقف؛ نقض SLA حل ثبت شده'
                    : 'متوقف (منتظر کاربر)',
                'color' => $hasResolutionBreach ? 'danger' : 'muted',
                'breached' => $hasResolutionBreach,
            ];
        }

        // A recorded breach is historical evidence and must not disappear
        // when a paused ticket resumes or is eventually closed.
        if ($hasResolutionBreach) {
            return [
                'state' => 'breached',
                'label' => 'نقض SLA حل ثبت شده',
                'color' => 'danger',
                'breached' => true,
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

        // First-response time continues while waiting for the customer. Record
        // a breach on the next lifecycle transition if the monitor has not yet
        // observed the overdue first response.
        $attributes = [];
        if (Schema::hasColumn('tickets', 'sla_first_due_at')
            && Schema::hasColumn('tickets', 'sla_first_breached_at')
            && $ticket->sla_first_due_at
            && empty($ticket->sla_first_breached_at)) {
            $firstResponseLate = $ticket->first_response_at
                ? $ticket->first_response_at->gt($ticket->sla_first_due_at)
                : $this->minutesUntilDeadline($ticket->sla_first_due_at) < 0;

            if ($firstResponseLate) {
                $attributes['sla_first_breached_at'] = now();
            }
        }

        if ($newStatus === 'pending' && $oldStatus !== 'pending') {
            $dueAt = $ticket->sla_resolve_due_at;
            $pausedAt = now();
            $remaining = $dueAt ? $this->minutesUntilDeadline($dueAt, $pausedAt) : null;
            $attributes['sla_paused_at'] = $pausedAt;
            $attributes['sla_resolve_remaining_minutes'] = $remaining === null ? null : max(0, $remaining);

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
            $attributes = array_merge($attributes, [
                'sla_resolve_due_at' => $remaining === null
                    ? $ticket->sla_resolve_due_at
                    : $this->addSlaMinutes(now(), (int) $remaining),
                'sla_paused_at' => null,
                'sla_resolve_remaining_minutes' => null,
            ]);

            if (Schema::hasColumn('tickets', 'sla_resolve_warned_at')
                && empty($ticket->sla_resolve_breached_at)) {
                // Resuming starts a new resolution deadline identity and warning window.
                $attributes['sla_resolve_warned_at'] = null;
            }

            return $attributes;
        }

        // Closed tickets leave the active SLA scan. Capture any breach that
        // became due before closure so the durable outbox can still deliver it.
        if ($newStatus === 'closed'
            && $oldStatus !== 'pending'
            && Schema::hasColumn('tickets', 'sla_resolve_breached_at')
            && $ticket->sla_resolve_due_at
            && empty($ticket->sla_resolve_breached_at)
            && $this->minutesUntilDeadline($ticket->sla_resolve_due_at) < 0) {
            $attributes['sla_resolve_breached_at'] = now();
        }

        return $attributes;
    }

    /**
     * Whether a local calendar date is eligible for SLA working-time accrual.
     *
     * @param array<int, int> $weekend
     */
    private function isBusinessDay(Carbon $date, array $weekend): bool
    {
        if (in_array((int) $date->dayOfWeek, $weekend, true)) {
            return false;
        }

        $holidays = (array) config('support.business_hours.holidays', []);

        return ! in_array($date->toDateString(), $holidays, true);
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
            if (! $this->isBusinessDay($cursor, $weekend)) {
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
    public function agentReport(?Carbon $from = null, ?Carbon $to = null, ?array $visibleUserIds = null): array
    {
        $from = $from ?: now()->subDays(30)->startOfDay();
        $to = $to ?: now()->endOfDay();

        $agents = [];
        $totals = [
            'tickets' => 0,
            'closed' => 0,
            'first_response_met' => 0,
            'first_response_total' => 0,
            'avg_first_response_minutes' => null,
        ];
        $allFirstHistogram = [];
        $allFirstSum = 0;
        $allFirstCount = 0;

        $ensureAgent = function (int $id, string $name) use (&$agents): void {
            $name = trim($name) !== '' ? $name : 'کارشناس نامشخص';
            if (! isset($agents[$id])) {
                $agents[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'assigned' => 0,
                    'closed' => 0,
                    'replies' => 0,
                    'first_response_met' => 0,
                    'first_response_total' => 0,
                    'first_response_sum' => 0,
                    'first_response_histogram' => [],
                ];
            } elseif ($agents[$id]['name'] === 'بدون ارجاع' && $name !== 'بدون ارجاع') {
                $agents[$id]['name'] = $name;
            }
        };

        // Keep ticket hydration bounded and never hydrate message bodies or all
        // public replies. Counts and first-reply candidates are aggregated in SQL.
        $visibleUserIds = $visibleUserIds === null
            ? null
            : array_values(array_unique(array_map('intval', $visibleUserIds)));

        Ticket::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($visibleUserIds !== null, fn ($query) => $query->whereIn('user_id', $visibleUserIds))
            ->with('assignee')
            ->chunkById(100, function ($tickets) use (&$agents, &$totals, &$allFirstHistogram, &$allFirstSum, &$allFirstCount, $ensureAgent, $visibleUserIds): void {
                $ticketIds = $tickets->modelKeys();
                if ($ticketIds === []) {
                    return;
                }

                $eligibleMessages = function () use ($ticketIds) {
                    return DB::table('ticket_messages as messages')
                        ->leftJoin('users as reply_author', 'reply_author.id', '=', 'messages.user_id')
                        ->whereIn('messages.ticket_id', $ticketIds)
                        ->where(function ($query): void {
                            $query->where('messages.is_staff', true)
                                ->orWhere('reply_author.role', User::ROLE_ADMIN);
                        })
                        ->where(function ($query): void {
                            $query->where('messages.is_internal', false)
                                ->orWhereNull('messages.is_internal');
                        });
                };

                // Count public staff replies by their actual author, matching
                // TicketMessage::isFromStaff() without loading message models.
                $replyCounts = $eligibleMessages()
                    ->whereNotNull('messages.user_id')
                    ->select([
                        'messages.user_id',
                        'reply_author.name as author_name',
                        'reply_author.email as author_email',
                    ])
                    ->selectRaw('COUNT(*) as reply_count')
                    ->groupBy('messages.user_id', 'reply_author.name', 'reply_author.email')
                    ->get();

                foreach ($replyCounts as $replyCount) {
                    $authorId = (int) $replyCount->user_id;
                    $authorName = $replyCount->author_name ?: ($replyCount->author_email ?: 'کارشناس نامشخص');

                    if ($visibleUserIds !== null && ! in_array($authorId, $visibleUserIds, true)) {
                        $authorId = -1;
                        $authorName = 'کارشناس خارج از محدوده';
                    }

                    $ensureAgent($authorId, $authorName);
                    $agents[$authorId]['replies'] += (int) $replyCount->reply_count;
                }

                // Find the earliest public staff reply per ticket. The second
                // aggregation chooses the lowest message ID for deterministic
                // handling when two replies share the same timestamp.
                $firstTimes = $eligibleMessages()
                    ->select('messages.ticket_id')
                    ->selectRaw('MIN(messages.created_at) as first_created_at')
                    ->groupBy('messages.ticket_id');

                $firstIds = DB::table('ticket_messages as first_messages')
                    ->joinSub($firstTimes, 'first_times', function ($join): void {
                        $join->on('first_messages.ticket_id', '=', 'first_times.ticket_id')
                            ->on('first_messages.created_at', '=', 'first_times.first_created_at');
                    })
                    ->select('first_messages.ticket_id')
                    ->selectRaw('MIN(first_messages.id) as first_message_id')
                    ->groupBy('first_messages.ticket_id');

                $firstReplies = DB::table('ticket_messages as first_messages')
                    ->joinSub($firstIds, 'first_ids', function ($join): void {
                        $join->on('first_messages.id', '=', 'first_ids.first_message_id');
                    })
                    ->leftJoin('users as reply_author', 'reply_author.id', '=', 'first_messages.user_id')
                    ->select([
                        'first_messages.ticket_id',
                        'first_messages.user_id',
                        'first_messages.created_at',
                        'reply_author.name as author_name',
                        'reply_author.email as author_email',
                    ])
                    ->get()
                    ->keyBy('ticket_id');

                foreach ($tickets as $ticket) {
                    $totals['tickets']++;
                    if ($ticket->status === 'closed') {
                        $totals['closed']++;
                    }

                    $assignedId = (int) ($ticket->assigned_to ?? 0);
                    $assignedName = $ticket->assignee?->name
                        ?? $ticket->assignee?->email
                        ?? 'بدون ارجاع';

                    if ($assignedId > 0 && $visibleUserIds !== null && ! in_array($assignedId, $visibleUserIds, true)) {
                        $assignedId = -1;
                        $assignedName = 'کارشناس خارج از محدوده';
                    }

                    $ensureAgent($assignedId, $assignedName);

                    if ($assignedId !== 0) {
                        $agents[$assignedId]['assigned']++;
                        if ($ticket->status === 'closed') {
                            $agents[$assignedId]['closed']++;
                        }
                    }

                    $firstStaff = $firstReplies->get($ticket->id);
                    // Retain SLA evidence from the canonical ticket timestamp when
                    // legacy public reply rows are missing or have been purged.
                    $firstResponseAt = $firstStaff?->created_at
                        ? Carbon::parse($firstStaff->created_at)
                        : ($ticket->first_response_at ? Carbon::parse($ticket->first_response_at) : null);

                    if (! $firstResponseAt) {
                        continue;
                    }
                    $minutes = $this->businessMinutesBetween($ticket->created_at, $firstResponseAt);
                    $allFirstHistogram[$minutes] = ($allFirstHistogram[$minutes] ?? 0) + 1;
                    $allFirstSum += $minutes;
                    $allFirstCount++;
                    $totals['first_response_total']++;

                    $met = ! $ticket->sla_first_due_at
                        || $firstResponseAt->lte($ticket->sla_first_due_at);

                    if ($met) {
                        $totals['first_response_met']++;
                    }

                    // If a legacy/system public response has no actor ID, retain
                    // the previous fallback to the assigned agent.
                    $responderId = (int) (($firstStaff?->user_id) ?: $assignedId);
                    $responderName = $firstStaff?->author_name
                        ?: ($firstStaff?->author_email ?: $assignedName);

                    if ($responderId > 0 && $visibleUserIds !== null && ! in_array($responderId, $visibleUserIds, true)) {
                        $responderId = -1;
                        $responderName = 'کارشناس خارج از محدوده';
                    }

                    if ($responderId !== 0) {
                        $ensureAgent($responderId, $responderName);
                        $agents[$responderId]['first_response_total']++;
                        $agents[$responderId]['first_response_sum'] += $minutes;
                        $agents[$responderId]['first_response_histogram'][$minutes]
                            = ($agents[$responderId]['first_response_histogram'][$minutes] ?? 0) + 1;

                        if ($met) {
                            $agents[$responderId]['first_response_met']++;
                        }
                    }
                }
            });

        foreach ($agents as &$row) {
            $count = (int) $row['first_response_total'];
            $row['avg_first_response_minutes'] = $count > 0
                ? (int) round($row['first_response_sum'] / $count)
                : null;
            $row['median_first_response_minutes'] = $this->medianFromHistogram($row['first_response_histogram']);
            $row['sla_rate'] = $count > 0
                ? round(100 * $row['first_response_met'] / $count, 1)
                : null;
            unset($row['first_response_sum'], $row['first_response_histogram']);
        }
        unset($row);

        $totals['avg_first_response_minutes'] = $allFirstCount > 0
            ? (int) round($allFirstSum / $allFirstCount)
            : null;
        $totals['median_first_response_minutes'] = $this->medianFromHistogram($allFirstHistogram);
        $totals['p90_first_response_minutes'] = $this->percentileFromHistogram($allFirstHistogram, 90);
        $totals['sla_rate'] = $totals['first_response_total'] > 0
            ? round(100 * $totals['first_response_met'] / $totals['first_response_total'], 1)
            : null;

        usort($agents, fn ($a, $b) => ($b['assigned'] <=> $a['assigned']) ?: ($b['replies'] <=> $a['replies']));

        return [
            'agents' => array_values($agents),
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Exact median from an integer-minute frequency map; memory grows with
     * distinct response-minute values rather than the number of tickets.
     *
     * @param array<int, int> $histogram
     */
    protected function medianFromHistogram(array $histogram): ?int
    {
        $count = array_sum($histogram);
        if ($count === 0) {
            return null;
        }

        $lower = $this->histogramValueAtRank($histogram, intdiv($count - 1, 2));
        $upper = $this->histogramValueAtRank($histogram, intdiv($count, 2));

        return (int) round(($lower + $upper) / 2);
    }

    /**
     * Preserve the existing linear-interpolation percentile definition exactly.
     *
     * @param array<int, int> $histogram
     */
    protected function percentileFromHistogram(array $histogram, float $percentile): ?int
    {
        $count = array_sum($histogram);
        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            return (int) array_key_first($histogram);
        }

        $rank = ($percentile / 100) * ($count - 1);
        $lowerRank = (int) floor($rank);
        $upperRank = (int) ceil($rank);
        $lower = $this->histogramValueAtRank($histogram, $lowerRank);

        if ($lowerRank === $upperRank) {
            return $lower;
        }

        $upper = $this->histogramValueAtRank($histogram, $upperRank);
        $weight = $rank - $lowerRank;

        return (int) round($lower * (1 - $weight) + $upper * $weight);
    }

    /** @param array<int, int> $histogram */
    protected function histogramValueAtRank(array $histogram, int $rank): int
    {
        ksort($histogram, SORT_NUMERIC);
        $seen = 0;

        foreach ($histogram as $minutes => $frequency) {
            $seen += $frequency;
            if ($rank < $seen) {
                return (int) $minutes;
            }
        }

        return (int) array_key_last($histogram);
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
