<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\SupportSlaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SupportReportController extends Controller
{
    public function __invoke(Request $request, SupportSlaService $sla)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $from = $request->date('from') ?: now()->subDays(30)->toDateString();
        $to = $request->date('to') ?: now()->toDateString();

        $fromAt = Carbon::parse($from)->startOfDay();
        $toAt = Carbon::parse($to)->endOfDay();

        $report = $sla->agentReport($fromAt, $toAt);

        $breached = Ticket::query()->slaBreached()->count();
        $backlog = Ticket::query()->openQueue()->count();
        $needsReply = Ticket::query()->needsReply()->count();
        $openInRange = Ticket::query()->whereBetween('created_at', [$fromAt, $toAt])->count();
        $closedInRange = Schema::hasColumn('tickets', 'closed_at')
            ? Ticket::query()->where('status', 'closed')->whereBetween('closed_at', [$fromAt, $toAt])->count()
            : Ticket::query()->where('status', 'closed')->whereBetween('updated_at', [$fromAt, $toAt])->count();
        $breachRate = $openInRange > 0
            ? round(100 * Ticket::query()->whereBetween('created_at', [$fromAt, $toAt])->slaBreached()->count() / max(1, $openInRange), 1)
            : null;

        $csat = [
            'count' => 0,
            'avg' => null,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ];

        if (Schema::hasColumn('tickets', 'csat_score')) {
            $rated = Ticket::query()
                ->whereNotNull('csat_score')
                ->whereBetween('csat_at', [$fromAt, $toAt])
                ->get(['csat_score']);

            $csat['count'] = $rated->count();
            if ($csat['count'] > 0) {
                $csat['avg'] = round($rated->avg('csat_score'), 2);
                foreach ($rated as $row) {
                    $s = (int) $row->csat_score;
                    if ($s >= 1 && $s <= 5) {
                        $csat['distribution'][$s]++;
                    }
                }
            }
        }

        return view('admin.support-report', compact('report', 'from', 'to', 'breached', 'csat', 'backlog', 'needsReply', 'openInRange', 'closedInRange', 'breachRate'));
    }
}
