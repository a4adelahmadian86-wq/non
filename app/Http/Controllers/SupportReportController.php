<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\SupportSlaService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SupportReportController extends Controller
{
    public function __invoke(Request $request, SupportSlaService $sla)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $from = $request->date('from') ?: now()->subDays(30)->toDateString();
        $to = $request->date('to') ?: now()->toDateString();

        $report = $sla->agentReport(
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay()
        );

        $breached = Ticket::query()->slaBreached()->count();

        return view('admin.support-report', compact('report', 'from', 'to', 'breached'));
    }
}
