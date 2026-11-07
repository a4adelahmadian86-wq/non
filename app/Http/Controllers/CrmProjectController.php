<?php

namespace App\Http\Controllers;

use App\Models\FarastProject;
use App\Models\Order;
use App\Models\User;
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

        $term = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $projectType = trim((string) $request->query('project_type', ''));

        if ($term !== '') {
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
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($projectType !== '') {
            $query->where('project_type', $projectType);
        }

        $projects = $query->paginate(min(max($request->integer('per_page', 25), 1), 100))->withQueryString();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $projects,
                'filters' => $request->only(['q', 'status', 'project_type']),
            ]);
        }

        $summaryQuery = FarastProject::query();
        if ($visibleIds !== null) {
            $summaryQuery->whereIn('user_id', $visibleIds);
        }
        $statusCounts = (clone $summaryQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = [
            'total' => (clone $summaryQuery)->count(),
            'active' => (int) ($statusCounts['active'] ?? 0),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
        ];

        return view('admin.crm.projects.index', [
            'projects' => $projects,
            'filters' => ['q' => $term, 'status' => $status, 'project_type' => $projectType],
            'stats' => $stats,
        ]);
    }

    public function assignHumanTask(Request $request, int $project, int $task, AdminConsoleService $console)
    {
        $record = FarastProject::query()->findOrFail($project);
        $console->authorizeAction($request->user(), 'users', (int) $record->user_id);

        $data = $request->validate([
            'specialist_id' => 'present|nullable|integer|exists:users,id',
        ]);

        if ($data['specialist_id'] !== null) {
            $specialist = \App\Models\User::query()
                ->whereKey($data['specialist_id'])
                ->whereIn('role', [
                    \App\Models\User::ROLE_ADMIN,
                    \App\Models\User::ROLE_ORGANIZATION_MANAGER,
                    \App\Models\User::ROLE_TEAM_SUPERVISOR,
                    \App\Models\User::ROLE_EMPLOYEE,
                ])
                ->where('is_blocked', false)
                ->firstOrFail();

            $console->authorizeAction($request->user(), 'users', (int) $specialist->id);
        }

        $humanTask = DB::table('farast_human_tasks')
            ->where('id', $task)
            ->where('project_id', $record->id)
            ->first();

        abort_unless($humanTask, 404);

        DB::table('farast_human_tasks')->where('id', $task)->update([
            'specialist_id' => $data['specialist_id'],
            'updated_at' => now(),
        ]);

        app(\App\Services\AuditLogService::class)->record($request->user()->id, 'crm.project.human_task.assigned', $request->ip(), [
            'project_id' => $record->id,
            'task_id' => $task,
            'specialist_id' => $data['specialist_id'],
        ]);

        return response()->json(['ok' => true, 'task_id' => $task, 'specialist_id' => $data['specialist_id']]);
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
                ->get();

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

        $financials = [
            'estimated_price_rials' => (int) $record->estimated_price_rials,
            'paid_rials' => (int) $record->paid_rials,
            'remaining_rials' => max(0, (int) $record->estimated_price_rials - (int) $record->paid_rials),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'project' => $record,
                'orders' => $orders->map(fn (Order $order) => [
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
                ]),
                'human_tasks' => $humanTasks,
                'financials' => $financials,
            ]);
        }

        $visibleIds = $console->visibleUserIds($request->user());
        $specialistsQuery = User::query()
            ->whereIn('role', [
                User::ROLE_ADMIN,
                User::ROLE_ORGANIZATION_MANAGER,
                User::ROLE_TEAM_SUPERVISOR,
                User::ROLE_EMPLOYEE,
            ])
            ->where('is_blocked', false)
            ->orderBy('name');
        if ($visibleIds !== null) {
            $specialistsQuery->whereIn('id', $visibleIds);
        }
        $specialists = $specialistsQuery->get(['id', 'name', 'role']);

        return view('admin.crm.projects.show', compact(
            'record', 'orders', 'humanTasks', 'financials', 'specialists'
        ));
    }
}
