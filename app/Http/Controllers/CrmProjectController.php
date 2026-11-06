<?php

namespace App\Http\Controllers;

use App\Models\FarastProject;
use App\Models\Order;
use App\Services\AdminConsoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CrmProjectController extends Controller
{
    public function index(Request $request, AdminConsoleService $console)
    {
        $console->authorizeAction($request->user(), 'users');

        $query = FarastProject::query()
            ->with('user:id,name,email,mobile')
            ->withCount(['documents', 'typingDocuments'])
            ->latest('updated_at');

        $visibleIds = $console->visibleUserIds($request->user());
        if ($visibleIds !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', '%'.$term.'%')
                    ->orWhere('project_type', 'like', '%'.$term.'%')
                    ->orWhereHas('user', function ($users) use ($term) {
                        $users->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('mobile', 'like', '%'.$term.'%');
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('project_type')) {
            $query->where('project_type', $request->query('project_type'));
        }

        return response()->json([
            'data' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100)),
            'filters' => $request->only(['q', 'status', 'project_type']),
        ]);
    }

    public function show(Request $request, int $project, AdminConsoleService $console)
    {
        $record = FarastProject::query()
            ->with([
                'user:id,name,email,mobile,organization_id',
                'documents:id,project_id,user_id,title,status,revision,updated_at',
                'typingDocuments:id,project_id,user_id,title,status,page_count,word_count,price_rials,updated_at',
            ])
            ->findOrFail($project);

        $console->authorizeAction($request->user(), 'users', (int) $record->user_id);

        $typingDocumentIds = $record->typingDocuments->pluck('id');
        $orders = $typingDocumentIds->isEmpty()
            ? collect()
            : Order::query()->with('payments')
                ->whereIn('document_id', $typingDocumentIds)
                ->where('user_id', $record->user_id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'document_id' => $order->document_id,
                    'status' => $order->status,
                    'total_rials' => $order->total_rials,
                    'paid_at' => $order->paid_at,
                    'payments' => $order->payments->map(fn ($payment) => [
                        'id' => $payment->id,
                        'gateway' => $payment->gateway,
                        'status' => $payment->status,
                        'amount_rials' => $payment->amount_rials,
                        'paid_at' => $payment->paid_at,
                    ])->values(),
                ]);

        $humanTasks = collect();
        if (Schema::hasTable('farast_human_tasks')) {
            $humanTasks = DB::table('farast_human_tasks')
                ->leftJoin('users as specialists', 'specialists.id', '=', 'farast_human_tasks.specialist_id')
                ->where('farast_human_tasks.project_id', $record->id)
                ->orderByDesc('farast_human_tasks.created_at')
                ->get([
                    'farast_human_tasks.id',
                    'farast_human_tasks.task_id',
                    'farast_human_tasks.capability',
                    'farast_human_tasks.status',
                    'farast_human_tasks.sla',
                    'farast_human_tasks.created_at',
                    'farast_human_tasks.updated_at',
                    'specialists.id as specialist_id',
                    'specialists.name as specialist_name',
                ]);
        }

        return response()->json([
            'project' => $record,
            'orders' => $orders,
            'human_tasks' => $humanTasks,
            'financials' => [
                'estimated_price_rials' => (int) $record->estimated_price_rials,
                'paid_rials' => (int) $record->paid_rials,
                'remaining_rials' => max(0, (int) $record->estimated_price_rials - (int) $record->paid_rials),
            ],
        ]);
    }
}
