<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Models\Ticket;
use App\Models\TypingDocument;
use App\Models\User;
use App\Services\AdminConsoleService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CrmController extends Controller
{
    private function authorizeCustomer(AdminConsoleService $console, Request $request, int $customerId): User
    {
        $console->authorizeAction($request->user(), 'users', $customerId);

        return User::query()->findOrFail($customerId);
    }

    public function customers(Request $request, AdminConsoleService $console)
    {
        $console->authorizeAction($request->user(), 'users');

        $query = User::query()
            ->withCount(['orders', 'tickets', 'documents'])
            ->withSum(['orders as paid_revenue_rials' => fn ($q) => $q->where('status', 'paid')], 'total_rials')
            ->latest('users.created_at');

        $visibleIds = $console->visibleUserIds($request->user());
        if ($visibleIds !== null) {
            $query->whereIn('users.id', $visibleIds);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('mobile', 'like', '%'.$term.'%');
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        if ($request->query('customer_status') === 'blocked') {
            $query->where('is_blocked', true);
        } elseif ($request->query('customer_status') === 'active') {
            $query->where('is_blocked', false);
        }

        return response()->json([
            'data' => $query->paginate(min(max($request->integer('per_page', 25), 1), 100)),
            'filters' => [
                'q' => $request->query('q'),
                'role' => $request->query('role'),
                'customer_status' => $request->query('customer_status'),
            ],
        ]);
    }

    public function show(Request $request, int $customer, AdminConsoleService $console)
    {
        $user = $this->authorizeCustomer($console, $request, $customer);

        $user->loadCount(['orders', 'tickets', 'documents'])
            ->loadSum(['orders as paid_revenue_rials' => fn ($q) => $q->where('status', 'paid')], 'total_rials');

        $orders = Order::query()->with('storeItems')
            ->where('user_id', $user->id)->latest()->limit(30)->get()
            ->map(fn (Order $order) => [
                'type' => 'order',
                'id' => $order->id,
                'title' => $order->isDeposit() ? 'سفارش پیش‌پرداخت تایپ' : 'سفارش',
                'status' => $order->status,
                'amount_rials' => $order->total_rials,
                'created_at' => $order->created_at,
                'items' => $order->storeItems->pluck('title_snapshot')->values(),
            ]);

        $tickets = Ticket::query()->where('user_id', $user->id)->latest()->limit(30)->get()
            ->map(fn (Ticket $ticket) => [
                'type' => 'ticket',
                'id' => $ticket->id,
                'title' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at,
            ]);

        $documents = TypingDocument::query()->where('user_id', $user->id)->latest()->limit(30)->get()
            ->map(fn (TypingDocument $document) => [
                'type' => 'typing_document',
                'id' => $document->id,
                'title' => $document->title,
                'status' => $document->status,
                'page_count' => $document->page_count,
                'created_at' => $document->created_at,
            ]);

        $payments = Payment::query()->whereHas('order', fn ($query) => $query->where('user_id', $user->id))
            ->latest()->limit(30)->get()->map(fn (Payment $payment) => [
                'type' => 'payment',
                'id' => $payment->id,
                'order_id' => $payment->order_id,
                'title' => 'پرداخت سفارش',
                'status' => $payment->status,
                'gateway' => $payment->gateway,
                'amount_rials' => $payment->amount_rials,
                'created_at' => $payment->created_at,
            ]);

        $wallet = $user->wallet()->first();
        $walletTransactions = $wallet
            ? WalletTransaction::query()->where('wallet_id', $wallet->id)->latest()->limit(30)->get()
                ->map(fn (WalletTransaction $transaction) => [
                    'type' => 'wallet_transaction',
                    'id' => $transaction->id,
                    'title' => $transaction->description ?: 'تراکنش کیف پول',
                    'status' => $transaction->type,
                    'amount_rials' => $transaction->amount_rials,
                    'balance_after_rials' => $transaction->balance_after,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id,
                    'created_at' => $transaction->created_at,
                ])
            : collect();

        $timeline = $orders->concat($tickets)->concat($documents)->concat($payments)->concat($walletTransactions)
            ->sortByDesc(fn (array $event) => $event['created_at']?->getTimestamp() ?? 0)
            ->take(60)->values();

        $notes = DB::table('crm_customer_notes')
            ->leftJoin('users as authors', 'authors.id', '=', 'crm_customer_notes.author_id')
            ->where('crm_customer_notes.user_id', $user->id)
            ->orderByDesc('crm_customer_notes.created_at')
            ->limit(100)
            ->get([
                'crm_customer_notes.id', 'crm_customer_notes.note', 'crm_customer_notes.visibility',
                'crm_customer_notes.created_at', 'authors.name as author_name',
            ]);

        $followUps = DB::table('crm_follow_up_tasks')
            ->leftJoin('users as assignees', 'assignees.id', '=', 'crm_follow_up_tasks.assigned_to')
            ->where('crm_follow_up_tasks.customer_id', $user->id)
            ->orderByRaw("CASE WHEN crm_follow_up_tasks.status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('crm_follow_up_tasks.due_at')
            ->get([
                'crm_follow_up_tasks.id', 'crm_follow_up_tasks.title', 'crm_follow_up_tasks.description',
                'crm_follow_up_tasks.status', 'crm_follow_up_tasks.priority', 'crm_follow_up_tasks.due_at',
                'crm_follow_up_tasks.completed_at', 'assignees.name as assignee_name',
            ]);

        return response()->json([
            'customer' => $user->only(['id', 'name', 'email', 'mobile', 'role', 'is_verified', 'is_blocked', 'created_at', 'organization_id']),
            'metrics' => [
                'orders_count' => $user->orders_count,
                'tickets_count' => $user->tickets_count,
                'typing_documents_count' => $user->documents_count,
                'payments_count' => Payment::query()->whereHas('order', fn ($query) => $query->where('user_id', $user->id))->count(),
                'wallet_transactions_count' => $wallet ? WalletTransaction::query()->where('wallet_id', $wallet->id)->count() : 0,
                'paid_revenue_rials' => (int) ($user->paid_revenue_rials ?? 0),
            ],
            'timeline' => $timeline,
            'notes' => $notes,
            'follow_ups' => $followUps,
        ]);
    }

    public function storeNote(Request $request, int $customer, AdminConsoleService $console)
    {
        $user = $this->authorizeCustomer($console, $request, $customer);
        $data = $request->validate([
            'note' => 'required|string|min:2|max:5000',
            'visibility' => 'sometimes|in:internal',
        ]);

        $id = DB::table('crm_customer_notes')->insertGetId([
            'user_id' => $user->id,
            'author_id' => $request->user()->id,
            'note' => trim($data['note']),
            'visibility' => 'internal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuditLogService::class)->record($request->user()->id, 'crm.customer.note.created', $request->ip(), [
            'customer_id' => $user->id,
            'note_id' => $id,
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function storeFollowUp(Request $request, int $customer, AdminConsoleService $console)
    {
        $user = $this->authorizeCustomer($console, $request, $customer);
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:4000',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'priority' => 'sometimes|in:low,normal,high',
            'due_at' => 'nullable|date',
        ]);

        if (! empty($data['assigned_to'])) {
            $console->authorizeAction($request->user(), 'users', (int) $data['assigned_to']);
        }

        $id = DB::table('crm_follow_up_tasks')->insertGetId([
            'customer_id' => $user->id,
            'assigned_to' => $data['assigned_to'] ?? $request->user()->id,
            'created_by' => $request->user()->id,
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
            'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AuditLogService::class)->record($request->user()->id, 'crm.follow_up.created', $request->ip(), [
            'customer_id' => $user->id,
            'task_id' => $id,
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateFollowUp(Request $request, int $customer, int $task, AdminConsoleService $console)
    {
        $user = $this->authorizeCustomer($console, $request, $customer);
        $data = $request->validate(['status' => 'required|in:open,completed,cancelled']);

        $followUp = DB::table('crm_follow_up_tasks')
            ->where('id', $task)->where('customer_id', $user->id)->first();

        abort_unless($followUp, 404);

        DB::table('crm_follow_up_tasks')->where('id', $task)->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed' ? now() : null,
            'updated_at' => now(),
        ]);

        app(AuditLogService::class)->record($request->user()->id, 'crm.follow_up.updated', $request->ip(), [
            'customer_id' => $user->id,
            'task_id' => $task,
            'status' => $data['status'],
        ]);

        return response()->json(['ok' => true]);
    }
}
