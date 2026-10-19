<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class SupportSlaService
{
    public function applyTargets(Ticket $ticket): void
    {
        if (! Schema::hasColumn('tickets', 'sla_first_due_at')) {
            return;
        }

        $priority = $ticket->priority ?: 'normal';
        $firstMins = (int) config("support.sla_first_response_minutes.{$priority}", 240);
        $resolveMins = (int) config("support.sla_resolution_minutes.{$priority}", 1440);
        $base = $ticket->created_at ? Carbon::parse($ticket->created_at) : now();

        $ticket->forceFill([
            'sla_first_due_at' => $base->copy()->addMinutes($firstMins),
            'sla_resolve_due_at' => $base->copy()->addMinutes($resolveMins),
        ])->saveQuietly();
    }

    /**
     * @return array{state:string,label:string,color:string,minutes_left:?int,breached:bool}
     */
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

        $mins = (int) now()->diffInMinutes($ticket->sla_first_due_at, false);
        if ($mins < 0) {
            return [
                'state' => 'breached',
                'label' => 'SLA گذشته ('.abs($mins).'د)',
                'color' => 'danger',
                'minutes_left' => $mins,
                'breached' => true,
            ];
        }
        if ($mins <= 30) {
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

    /**
     * @return array{state:string,label:string,color:string,breached:bool}
     */
    public function resolutionStatus(Ticket $ticket): array
    {
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
                'state' => 'met',
                'label' => 'بسته شده',
                'color' => 'ok',
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

        $mins = (int) now()->diffInMinutes($ticket->sla_resolve_due_at, false);
        if ($mins < 0) {
            return [
                'state' => 'breached',
                'label' => 'مهلت حل گذشته',
                'color' => 'danger',
                'breached' => true,
            ];
        }

        return [
            'state' => 'ok',
            'label' => $this->humanMinutes($mins).' تا مهلت حل',
            'color' => $mins <= 60 ? 'warn' : 'ok',
            'breached' => false,
        ];
    }

    public function markResolved(Ticket $ticket): void
    {
        if (Schema::hasColumn('tickets', 'resolved_at') && empty($ticket->resolved_at)) {
            $ticket->forceFill(['resolved_at' => now()])->saveQuietly();
        }
    }

    /**
     * گزارش عملکرد کارشناسان در بازه زمانی.
     *
     * @return array{agents:array<int,array>,totals:array}
     */
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

            // اولین پاسخ staff
            $firstStaff = $ticket->messages->first(function ($m) {
                return $m->isFromStaff() && ! $m->is_internal;
            });
            if ($firstStaff) {
                $mins = $ticket->created_at->diffInMinutes($firstStaff->created_at);
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
            $row['sla_rate'] = $row['first_response_total'] > 0
                ? round(100 * $row['first_response_met'] / $row['first_response_total'], 1)
                : null;
            unset($row['first_response_samples']);
        }
        unset($row);

        $totals['avg_first_response_minutes'] = count($allFirst)
            ? (int) round(array_sum($allFirst) / count($allFirst))
            : null;
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
