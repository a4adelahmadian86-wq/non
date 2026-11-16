<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Http\Middleware\ResolveAdminScope;
use App\Services\SupportSlaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SupportReportController extends Controller
{
    public function __invoke(Request $request, SupportSlaService $sla)
    {
        $from = $request->date('from') ?: now()->subDays(30)->toDateString();
        $to = $request->date('to') ?: now()->toDateString();

        $fromAt = Carbon::parse($from)->startOfDay();
        $toAt = Carbon::parse($to)->endOfDay();

        $actor = $request->user();
        $requestScope = $request->attributes->get('farast.scope');
        $scope = is_string($requestScope)
            ? $requestScope
            : ResolveAdminScope::scopeWithConfiguredOverride($actor);
        $visibleUserIds = ResolveAdminScope::visibleUserIds($actor, $scope);

        // Apply one canonical ownership boundary to every report metric. A null
        // visible-user list means global scope; an empty list intentionally returns
        // no customer-owned records. Guest tickets (user_id IS NULL) remain global-only.
        $scopedTickets = static function ($query) use ($visibleUserIds) {
            if ($visibleUserIds !== null) {
                $query->whereIn('user_id', $visibleUserIds);
            }

            return $query;
        };

        $report = $sla->agentReport($fromAt, $toAt, $visibleUserIds);

        $breached = $scopedTickets(Ticket::query())->slaBreached()->count();
        $backlog = $scopedTickets(Ticket::query())->openQueue()->count();
        $needsReply = $scopedTickets(Ticket::query())->needsReply()->count();
        $openInRange = $scopedTickets(Ticket::query())->whereBetween('created_at', [$fromAt, $toAt])->count();
        $closedInRange = Schema::hasColumn('tickets', 'closed_at')
            ? $scopedTickets(Ticket::query())->where('status', 'closed')->whereBetween('closed_at', [$fromAt, $toAt])->count()
            : $scopedTickets(Ticket::query())->where('status', 'closed')->whereBetween('updated_at', [$fromAt, $toAt])->count();
        $breachRate = $openInRange > 0
            ? round(100 * $scopedTickets(Ticket::query())->whereBetween('created_at', [$fromAt, $toAt])->slaBreached()->count() / max(1, $openInRange), 1)
            : null;

        $csat = [
            'count' => 0,
            'avg' => null,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ];

        if (Schema::hasColumn('tickets', 'csat_score') && Schema::hasColumn('tickets', 'csat_at')) {
            // Aggregate in SQL instead of hydrating every rated ticket into PHP.
            // Report memory now stays constant as the historical rating volume grows.
            $rated = $scopedTickets(Ticket::query())
                ->whereNotNull('csat_score')
                ->whereBetween('csat_at', [$fromAt, $toAt])
                ->selectRaw('COUNT(*) AS rated_count, AVG(csat_score) AS rated_average')
                ->selectRaw('SUM(CASE WHEN csat_score = 1 THEN 1 ELSE 0 END) AS score_1')
                ->selectRaw('SUM(CASE WHEN csat_score = 2 THEN 1 ELSE 0 END) AS score_2')
                ->selectRaw('SUM(CASE WHEN csat_score = 3 THEN 1 ELSE 0 END) AS score_3')
                ->selectRaw('SUM(CASE WHEN csat_score = 4 THEN 1 ELSE 0 END) AS score_4')
                ->selectRaw('SUM(CASE WHEN csat_score = 5 THEN 1 ELSE 0 END) AS score_5')
                ->first();

            $csat['count'] = (int) ($rated?->rated_count ?? 0);
            if ($csat['count'] > 0) {
                $csat['avg'] = round((float) $rated->rated_average, 2);
                foreach (range(1, 5) as $score) {
                    $csat['distribution'][$score] = (int) ($rated->{'score_'.$score} ?? 0);
                }
            }
        }

        return view('admin.support-report', compact('report', 'from', 'to', 'breached', 'csat', 'backlog', 'needsReply', 'openInRange', 'closedInRange', 'breachRate'));
    }
}
