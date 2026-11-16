<?php

namespace App\Http\Controllers;

use App\Models\StoreLibraryItem;
use App\Models\FarastAuditEvent;
use App\Models\StoreProduct;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\StorageManagedFile;
use App\Models\User;
use App\Services\AdminConsoleService;
use App\Services\SupportSlaService;
use App\Services\AuthorizationService;
use App\Services\CapabilityService;
use App\Services\SupportTicketService;
use App\Services\SupportTicketStatusPolicy;
use App\Services\Storage\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    private const THREAD_HISTORY_PAGE_SIZE = 100;

    public function __construct(private readonly SupportTicketService $tickets)
    {
    }

    public function index(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403, 'پشتیبانی برای این حساب فعال نیست.');
        $this->tickets->claimGuestTickets(auth()->user());

        $query = auth()->user()->tickets();
        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', '%'.$q.'%')
                    ->orWhere('id', $q)
                    ->orWhere('ticket_code', 'like', '%'.$q.'%');
            });
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $query->orderByDesc('last_reply_at');
        }

        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [5, 10, 15, 20, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $tickets = $query->latest()->paginate($perPage)->withQueryString();

        return view('support.index', compact('tickets'));
    }

    public function createForm(CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);

        $purchasedProducts = collect();
        if (Schema::hasTable('store_library_items')) {
            $purchasedProducts = StoreLibraryItem::query()
                ->where('user_id', auth()->id())->whereNull('revoked_at')
                ->with('product:id,title,slug')->latest('granted_at')->get()
                ->filter(fn ($item) => $item->product)->unique('product_id')->take(5)->values()
                ->map(fn ($item) => $item->product);
        }

        $catalogProducts = collect();
        if (Schema::hasTable('store_products')) {
            $catalogProducts = StoreProduct::query()->published()->orderBy('title')->limit(80)->get(['id', 'title', 'slug']);
        }

        $services = [
            'typing' => 'تایپ آنلاین', 'convert' => 'تبدیل فایل', 'edit' => 'ویرایش هوشمند',
            'ocr' => 'OCR / استخراج متن', 'voice' => 'ورود صوتی', 'other' => 'سایر خدمات',
        ];

        return view('support.create', compact('purchasedProducts', 'catalogProducts', 'services'));
    }

    public function show(Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        $this->authorize('view', $ticket);

        $ticket->load(['relatedProduct', 'assignee']);
        $history = $this->initialThreadHistory($ticket, false);
        $ticket->setRelation('messages', $history['messages']);
        $this->tickets->markCustomerSeen($ticket);

        return view('support.show', compact('ticket', 'history'));
    }

    public function pollMessages(Request $request, Ticket $ticket, CapabilityService $capabilities, AuthorizationService $authorization)
    {
        $canSeeInternal = app(AdminConsoleService::class)->sectionAllowed(auth()->user(), 'support_inbox')
            && $authorization->allowsRecord(auth()->user(), 'support.manage', $ticket);
        abort_unless($capabilities->allowed(auth()->user(), 'can_support') || $canSeeInternal, 403);
        $this->authorize('view', $ticket);

        $formatMessage = function ($message) use ($ticket): array {
            $isStaff = $message->isFromStaff();

            return [
                'id' => $message->id,
                'body' => $message->body,
                'is_staff' => $isStaff,
                'is_internal' => (bool) $message->is_internal,
                'is_mine' => $message->user_id === auth()->id() && ! $isStaff,
                'time' => $message->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i'),
                'attachments' => collect($message->attachments ?? [])->values()->map(fn ($attachment, $index) => [
                    'name' => $attachment['name'] ?? 'پیوست',
                    'url' => route('support.attachment', ['ticket' => $ticket->id, 'message' => $message->id, 'index' => $index]),
                    'available' => ! empty($attachment['managed_file_id']) && empty($attachment['quarantined']),
                    'quarantined' => ! empty($attachment['quarantined']),
                ])->all(),
            ];
        };

        $beforeId = max(0, (int) $request->input('before_id', 0));
        if ($beforeId > 0) {
            $scannedMessages = $ticket->messages()
                ->with('user')
                ->where('id', '<', $beforeId)
                ->reorder('id', 'desc')
                ->limit(self::THREAD_HISTORY_PAGE_SIZE)
                ->get();

            $nextBeforeId = (int) ($scannedMessages->last()?->id ?? $beforeId);
            $hasMore = $nextBeforeId < $beforeId
                && $ticket->messages()->where('id', '<', $nextBeforeId)->exists();

            if (! $canSeeInternal) {
                $scannedMessages = $scannedMessages->reject(fn ($message) => (bool) $message->is_internal);
            }

            return response()->json([
                'ok' => true,
                'messages' => $scannedMessages->reverse()->values()->map($formatMessage)->values(),
                'next_before_id' => $nextBeforeId,
                'has_more' => $hasMore,
            ]);
        }

        $afterId = max(0, (int) $request->input('after_id', 0));

        // Scan a bounded batch before applying visibility rules. Customer cursors
        // must advance over private notes without receiving their contents.
        $scannedMessages = $ticket->messages()
            ->with('user')
            ->where('id', '>', $afterId)
            ->reorder('id')
            ->limit(self::THREAD_HISTORY_PAGE_SIZE)
            ->get();

        $lastScannedId = (int) ($scannedMessages->last()?->id ?? $afterId);

        if (! $canSeeInternal) {
            $scannedMessages = $scannedMessages->reject(fn ($message) => (bool) $message->is_internal);
        }

        $messages = $scannedMessages->map($formatMessage)->values();

        if (! $canSeeInternal && $messages->isNotEmpty()) {
            $this->tickets->markCustomerSeen($ticket);
        }

        $freshTicket = $ticket->fresh();

        return response()->json([
            'ok' => true,
            'messages' => $messages,
            'last_id' => $lastScannedId,
            'status' => $freshTicket->status,
            'status_label' => $freshTicket->statusLabel(),
        ]);
    }

    public function create(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'subject' => 'required|string|max:160',
            'body' => 'required|string|max:5000',
            'department' => 'required|string|in:'.implode(',', SupportTicketService::DEPARTMENTS),
            'priority' => 'required|string|in:'.implode(',', SupportTicketService::PRIORITIES),
            'topic_area' => ['required', 'string', Rule::in(SupportTicketService::TOPIC_AREAS)],
            'related_service' => [Rule::requiredIf(fn () => $request->input('topic_area') === SupportTicketService::TOPIC_AREA_SERVICES), 'nullable', 'string', 'max:80'],
            'related_product_id' => [Rule::requiredIf(fn () => $request->input('topic_area') === SupportTicketService::TOPIC_AREA_STORE), 'nullable', 'integer', Rule::exists('store_products', 'id')],
            'attachment' => 'nullable|file|max:5120|mimes:txt,jpg,jpeg,pdf,png',
            'submission_key' => 'required|uuid',
        ], [
            'department.required' => 'دپارتمان را انتخاب کنید.',
            'priority.required' => 'اولویت را انتخاب کنید.',
            'topic_area.required' => 'حوزه موضوع را انتخاب کنید.',
            'related_service.required' => 'نوع خدمت را انتخاب کنید.',
            'related_product_id.required' => 'یک محصول مرتبط انتخاب کنید.',
        ]);

        $body = $data['body'];
        $meta = [];
        if ($data['topic_area'] === SupportTicketService::TOPIC_AREA_SERVICES && ! empty($data['related_service'])) {
            $labels = ['typing' => 'تایپ آنلاین', 'convert' => 'تبدیل فایل', 'edit' => 'ویرایش هوشمند', 'ocr' => 'OCR / استخراج متن', 'voice' => 'ورود صوتی', 'other' => 'سایر خدمات'];
            $meta[] = 'حوزه: خدمات — '.($labels[$data['related_service']] ?? $data['related_service']);
        }
        if ($data['topic_area'] === SupportTicketService::TOPIC_AREA_STORE && ! empty($data['related_product_id'])) {
            $product = StoreProduct::find($data['related_product_id']);
            $meta[] = 'حوزه: فروشگاه — محصول: '.($product?->title ?? ('#'.$data['related_product_id']));
        }
        if ($meta) {
            $body = implode("\n", $meta)."\n\n".$body;
        }

        $ticket = $this->tickets->createTicket(auth()->user(), [
            'subject' => $data['subject'],
            'body' => $body,
            'department' => $data['department'],
            'priority' => $data['priority'],
            'topic_area' => $data['topic_area'],
            'related_service' => $data['topic_area'] === SupportTicketService::TOPIC_AREA_SERVICES ? ($data['related_service'] ?? null) : null,
            'related_product_id' => $data['topic_area'] === SupportTicketService::TOPIC_AREA_STORE ? ($data['related_product_id'] ?? null) : null,
            'source' => SupportTicketService::SOURCE_DASHBOARD,
            '_submission_scope' => 'user:'.auth()->id(),
            '_submission_key' => $data['submission_key'],
        ], $request->file('attachment'));

        return redirect()->route('support.show', $ticket)->with('status', 'تیکت '.$ticket->displayCode().' ثبت شد.');
    }

    public function message(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'attachment' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,gif,pdf,txt,zip',
            'submission_key' => 'required|uuid',
        ]);

        $msg = $this->tickets->addMessage($ticket, auth()->user(), $data['body'], [
            'is_staff' => false,
            'attachment' => $request->file('attachment'),
            '_submission_scope' => 'user:'.auth()->id(),
            '_submission_key' => $data['submission_key'],
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'message' => [
                    'id' => $msg->id,
                    'body' => $msg->body,
                    'is_staff' => false,
                    'created_at' => $msg->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'time' => $msg->created_at->format('H:i'),
                    'attachments' => collect($msg->attachments ?? [])->values()->map(fn ($attachment, $index) => [
                        'name' => $attachment['name'] ?? 'پیوست',
                        'url' => route('support.attachment', ['ticket' => $ticket->id, 'message' => $msg->id, 'index' => $index]),
                        'available' => ! empty($attachment['managed_file_id']) && empty($attachment['quarantined']),
                        'quarantined' => ! empty($attachment['quarantined']),
                    ])->all(),
                ],
            ]);
        }

        return back();
    }

    public function close(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        $this->authorize('close', $ticket);

        $this->tickets->changeStatus($ticket, 'closed', auth()->user(), true);

        return redirect()->route('support.show', $ticket)->with('status', 'تیکت بسته شد. لطفاً به کیفیت پشتیبانی امتیاز دهید.');
    }

    /**
     * رضایت‌سنجی پس از بستن تیکت (CSAT 1–5).
     */
    public function csat(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        $this->authorize('rate', $ticket);

        $data = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        try {
            $this->tickets->submitCsat($ticket, (int) $data['score'], $data['comment'] ?? null, auth()->user());
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['csat' => $e->getMessage()]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => 'از بازخورد شما سپاسگزاریم.']);
        }

        return back()->with('status', 'از امتیاز شما سپاسگزاریم.');
    }

    public function adminIndex(Request $request, SupportSlaService $sla)
    {
        // Apply the current RBAC data scope before filtering, searching, counting,
        // pagination or resolving the active ticket. Guest tickets (user_id=null)
        // remain visible only to global support operators.
        $visibleUserIds = app(AdminConsoleService::class)->visibleUserIds(auth()->user());
        $scopeQuery = static function ($query) use ($visibleUserIds) {
            if ($visibleUserIds !== null) {
                $query->whereIn('tickets.user_id', $visibleUserIds);
            }

            return $query;
        };

        $warningDeadline = $sla->addSlaMinutes(
            now(),
            max(0, (int) config('support.warn_before_minutes', 30))
        );

        // Inbox rows need only the latest public preview. Loading every message
        // for every ticket on the page caused memory growth proportional to all
        // conversation history; the active thread is loaded separately below.
        $latestPublicMessageBody = TicketMessage::query()
            ->select('body')
            ->whereColumn('ticket_id', 'tickets.id')
            ->where(function ($q) {
                $q->where('is_internal', false)->orWhereNull('is_internal');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $query = $scopeQuery(Ticket::query())
            ->addSelect(['last_public_message_body' => $latestPublicMessageBody])
            ->with(['user', 'relatedProduct', 'assignee']);

        // Build assignee filter choices exclusively from tickets already inside
        // the current operator's record scope. Do not enumerate the global user
        // directory from a scoped inbox.
        $assigneeOptions = collect();
        $availableAssigneeIds = collect();
        if (Schema::hasColumn('tickets', 'assigned_to')) {
            $scopedAssigneeQuery = $scopeQuery(Ticket::query())
                ->whereNotNull('tickets.assigned_to');

            $availableAssigneeIds = (clone $scopedAssigneeQuery)
                ->distinct()
                ->orderBy('tickets.assigned_to')
                ->pluck('tickets.assigned_to')
                ->map(fn ($id) => (int) $id)
                ->values();

            // Assignee IDs are derived only from tickets the operator may see.
            // Do not intersect them with visibleUserIds: that list represents
            // ticket owners and intentionally excludes staff/admin accounts,
            // which are valid assignees and already visible on these ticket rows.
            $assigneeOptions = $availableAssigneeIds->isNotEmpty()
                ? User::query()->whereIn('id', $availableAssigneeIds)->orderBy('name')->get(['id', 'name', 'email'])
                : collect();
        }

        $statusFilter = $request->input('status');
        if (is_string($statusFilter)
            && $statusFilter !== 'all'
            && in_array($statusFilter, array_merge([SupportTicketService::STATUS_FILTER_NEEDS_REPLY], SupportTicketService::STATUSES), true)) {
            if ($statusFilter === SupportTicketService::STATUS_FILTER_NEEDS_REPLY) {
                $query->needsReply();
            } else {
                $query->where('status', $statusFilter);
            }
        }

        $departmentFilter = $request->input('department');
        if (is_string($departmentFilter) && in_array($departmentFilter, SupportTicketService::DEPARTMENTS, true)) {
            $query->where('department', $departmentFilter);
        }

        $priorityFilter = $request->input('priority');
        if (is_string($priorityFilter) && in_array($priorityFilter, SupportTicketService::PRIORITIES, true)) {
            $query->where('priority', $priorityFilter);
        }

        // These are the source and topic values currently emitted by the
        // canonical ticket creation flows. Ignore unknown values rather than
        // turning arbitrary query parameters into hidden/empty inbox states.
        if (Schema::hasColumn('tickets', 'source')
            && in_array($request->input('source'), SupportTicketService::SOURCES, true)) {
            $query->where('source', $request->input('source'));
        }
        if (Schema::hasColumn('tickets', 'topic_area')
            && in_array($request->input('topic_area'), SupportTicketService::TOPIC_AREAS, true)) {
            $query->where('topic_area', $request->input('topic_area'));
        }

        $assignedFilter = $request->input('assigned');
        if (Schema::hasColumn('tickets', 'assigned_to')) {
            if ($assignedFilter === SupportTicketService::ASSIGNMENT_FILTER_ME) {
                $query->where('assigned_to', auth()->id());
            } elseif ($assignedFilter === SupportTicketService::ASSIGNMENT_FILTER_UNASSIGNED) {
                $query->whereNull('assigned_to');
            } elseif (is_string($assignedFilter)
                && ctype_digit($assignedFilter)
                && (int) $assignedFilter > 0
                && $availableAssigneeIds->contains((int) $assignedFilter)) {
                // A forged/unknown assignee ID is ignored; even valid IDs only
                // narrow the already-scoped ticket query above.
                $query->where('assigned_to', (int) $assignedFilter);
            }
        }

        $slaFilter = $request->input('sla');
        if ($slaFilter === SupportTicketService::SLA_FILTER_BREACHED) {
            $query->slaBreached();
        }
        if ($slaFilter === SupportTicketService::SLA_FILTER_AT_RISK) {
            if (Schema::hasColumn('tickets', 'first_response_at') && Schema::hasColumn('tickets', 'sla_first_due_at') && Schema::hasColumn('tickets', 'sla_resolve_due_at')) {
                $query->where(function ($q) use ($warningDeadline) {
                        if (Schema::hasColumn('tickets', 'first_response_at') && Schema::hasColumn('tickets', 'sla_first_due_at')) {
                            $q->where(function ($w) use ($warningDeadline) {
                                $w->whereNull('first_response_at')
                                    ->whereNotNull('sla_first_due_at')
                                    ->where('sla_first_due_at', '>', now())
                                    ->where('sla_first_due_at', '<=', $warningDeadline);
                            });
                        }
                        if (Schema::hasColumn('tickets', 'sla_resolve_due_at')) {
                            $q->orWhere(function ($w) use ($warningDeadline) {
                                $w->whereNotIn('status', ['closed', 'pending'])
                                    ->whereNotNull('sla_resolve_due_at')
                                    ->where('sla_resolve_due_at', '>', now())
                                    ->where('sla_resolve_due_at', '<=', $warningDeadline);
                            });
                        }
                    });
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        $createdFrom = $this->parseInboxDateFilter($request->input('created_from'));
        $createdTo = $this->parseInboxDateFilter($request->input('created_to'));
        if ($createdFrom && $createdTo && $createdTo->lt($createdFrom)) {
            // An invalid range is ignored as a whole instead of producing a
            // confusing empty inbox.
            $createdFrom = null;
            $createdTo = null;
        }
        if ($createdFrom) {
            $query->where('tickets.created_at', '>=', $createdFrom->copy()->startOfDay());
        }
        if ($createdTo) {
            $query->where('tickets.created_at', '<', $createdTo->copy()->addDay()->startOfDay());
        }

        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
                if (Schema::hasColumn('tickets', 'ticket_code')) {
                    $w->orWhere('ticket_code', 'like', '%'.$q.'%');
                }
                if (Schema::hasColumn('tickets', 'guest_email')) {
                    $w->orWhere('guest_email', 'like', '%'.$q.'%');
                }
                if (Schema::hasColumn('tickets', 'guest_name')) {
                    $w->orWhere('guest_name', 'like', '%'.$q.'%');
                }
            });
        }

        $query->orderByRaw("CASE
            WHEN status = 'user_replied' THEN 0
            WHEN status = 'open' THEN 1
            WHEN status = 'pending' THEN 2
            WHEN status = 'answered' THEN 3
            ELSE 4 END")
            ->orderByRaw('CASE WHEN priority = ? THEN 0 WHEN priority = ? THEN 1 ELSE 2 END', [SupportTicketService::PRIORITY_HIGH, SupportTicketService::PRIORITY_NORMAL])
            ->orderByDesc('updated_at');

        $tickets = $query->paginate(25)->withQueryString();

        $active = null;
        $activeHistory = null;
        if ($request->filled('ticket')) {
            $activeQuery = Ticket::with(['user', 'relatedProduct', 'assignee'])
                ->whereKey($request->integer('ticket'));
            $scopeQuery($activeQuery);
            $active = $activeQuery->first();
            if ($active) {
                $this->authorize('manage', $active);
            }
        }
        if (! $active) {
            $firstTicketId = $tickets->getCollection()->first()?->id;
            if ($firstTicketId) {
                $activeQuery = Ticket::with(['user', 'relatedProduct', 'assignee'])
                    ->whereKey($firstTicketId);
                $scopeQuery($activeQuery);
                $active = $activeQuery->first();
                if ($active) {
                    $this->authorize('manage', $active);
                }
            }
        }

        if ($active) {
            $history = $this->initialThreadHistory($active, true);
            $active->setRelation('messages', $history['messages']);
            $activeHistory = $history;
        }

        // Audit records are queried only after the active ticket has passed the
        // same RBAC record-scope check used by the inbox.
        $auditEvents = $active
            ? FarastAuditEvent::query()
                ->where('aggregate_type', 'ticket')
                ->where('aggregate_id', $active->id)
                ->where('source', 'support')
                ->latest('id')
                ->limit(50)
                ->get()
                ->reverse()
                ->values()
            : collect();

        $auditUserIds = $auditEvents->flatMap(fn (FarastAuditEvent $event) => [
            $event->user_id,
            data_get($event->payload_meta, 'from_assigned_to'),
            data_get($event->payload_meta, 'to_assigned_to'),
        ])->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $auditUsers = $auditUserIds->isNotEmpty()
            ? User::query()->whereIn('id', $auditUserIds)->get(['id', 'name'])->keyBy('id')
            : collect();

        $visibleAuditUserIds = $visibleUserIds === null
            ? null
            : collect($visibleUserIds)->map(fn ($id) => (int) $id)->unique();

        $auditUserLabels = collect();
        foreach ($auditUsers as $auditUser) {
            $isVisibleAuditActor = $visibleAuditUserIds === null
                || $visibleAuditUserIds->contains((int) $auditUser->id);

            if ((int) $auditUser->id === (int) auth()->id()) {
                $auditUserLabels[$auditUser->id] = $auditUser->name;
            } elseif ($active && (int) $auditUser->id === (int) $active->user_id) {
                $auditUserLabels[$auditUser->id] = 'مشتری';
            } elseif ($isVisibleAuditActor) {
                $auditUserLabels[$auditUser->id] = $auditUser->name;
            } else {
                // Do not disclose names of historical actors/assignees outside
                // the current operator's effective record scope.
                $auditUserLabels[$auditUser->id] = 'کارشناس دیگر';
            }
        }

        $stats = [
            'needs_reply' => $scopeQuery(Ticket::query())->needsReply()->count(),
            'open' => $scopeQuery(Ticket::query())->where('status', 'open')->count(),
            'user_replied' => $scopeQuery(Ticket::query())->where('status', 'user_replied')->count(),
            'answered' => $scopeQuery(Ticket::query())->where('status', 'answered')->count(),
            'pending' => $scopeQuery(Ticket::query())->where('status', 'pending')->count(),
            'closed' => $scopeQuery(Ticket::query())->where('status', 'closed')->count(),
            'high' => $scopeQuery(Ticket::query())->where('priority', 'high')->whereNotIn('status', ['closed'])->count(),
        
            'sla_breached' => $scopeQuery(Ticket::query())->slaBreached()->count(),
            'sla_at_risk' => (Schema::hasColumn('tickets', 'first_response_at') && Schema::hasColumn('tickets', 'sla_first_due_at') && Schema::hasColumn('tickets', 'sla_resolve_due_at'))
                ? $scopeQuery(Ticket::query())->where(function ($q) use ($warningDeadline) {
                    if (Schema::hasColumn('tickets', 'first_response_at') && Schema::hasColumn('tickets', 'sla_first_due_at')) {
                        $q->where(function ($w) use ($warningDeadline) {
                            $w->whereNull('first_response_at')->whereNotNull('sla_first_due_at')->where('sla_first_due_at', '>', now())->where('sla_first_due_at', '<=', $warningDeadline);
                        });
                    }
                    if (Schema::hasColumn('tickets', 'sla_resolve_due_at')) {
                        $q->orWhere(function ($w) use ($warningDeadline) {
                            $w->whereNotIn('status', ['closed', 'pending'])->whereNotNull('sla_resolve_due_at')->where('sla_resolve_due_at', '>', now())->where('sla_resolve_due_at', '<=', $warningDeadline);
                        });
                    }
                })->count()
                : 0,
        ];

        $agents = $active ? $this->tickets->eligibleAgentsFor($active) : collect();

        $canned = config('support.canned_replies', [
            'سلام، پیام شما دریافت شد. در حال بررسی هستیم و به‌زودی پاسخ می‌دهیم.',
            'برای پیگیری دقیق‌تر لطفاً اسکرین‌شات یا جزئیات بیشتری ارسال کنید.',
            'مشکل بررسی و برطرف شد. لطفاً مجدداً امتحان کنید و نتیجه را اعلام بفرمایید.',
            'با تشکر از صبوری شما. تیکت بسته شد. در صورت نیاز تیکت جدید ثبت کنید.',
        ]);

        return view('admin.support', compact('tickets', 'active', 'stats', 'canned', 'agents', 'assigneeOptions', 'activeHistory', 'auditEvents', 'auditUserLabels'));
    }

    public function adminReply(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:8000',
            'status' => 'nullable|in:'.implode(',', SupportTicketStatusPolicy::STAFF_MANAGED_STATUSES),
            'is_internal' => 'nullable|boolean',
            'attachment' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,gif,pdf,txt,zip,doc,docx',
            'submission_key' => 'required|uuid',
        ]);

        $isInternal = (bool) ($data['is_internal'] ?? false);

        $this->tickets->addMessage($ticket, auth()->user(), $data['body'], [
            'is_staff' => true,
            'is_internal' => $isInternal,
            'status' => $data['status'] ?? 'answered',
            'attachment' => $request->file('attachment'),
            'touch_status' => ! $isInternal || ! empty($data['status']),
            '_submission_scope' => 'user:'.auth()->id(),
            '_submission_key' => $data['submission_key'],
        ]);

        // The reply POST body also contains a `status` field for the destination
        // ticket state. Read inbox filters from the query string so the reply
        // destination cannot overwrite the active inbox status filter.
        $inboxFilters = array_intersect_key($request->query(), array_flip([
            'status',
            'q',
            'department',
            'priority',
            'source',
            'topic_area',
            'sla',
            'assigned',
            'created_from',
            'created_to',
        ]));

        return redirect()
            ->route('admin.support.management', ['ticket' => $ticket->id] + $inboxFilters)
            ->with('status', $isInternal ? 'یادداشت داخلی ثبت شد.' : 'پاسخ ثبت شد.');
    }

    public function adminStatus(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', SupportTicketStatusPolicy::STAFF_MANAGED_STATUSES),
        ]);

        $this->tickets->changeStatus($ticket, $data['status'], auth()->user(), true);

        return back()->with('status', 'وضعیت تیکت به‌روزرسانی شد.');
    }

    public function adminAssign(Request $request, Ticket $ticket)
    {
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $agent = ! empty($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        $this->tickets->assign($ticket, $agent, auth()->user());

        return back()->with('status', 'ارجاع تیکت ذخیره شد.');
    }


    public function adminMeta(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'priority' => 'nullable|in:'.implode(',', SupportTicketService::PRIORITIES),
            'department' => 'nullable|in:'.implode(',', SupportTicketService::DEPARTMENTS),
        ]);

        $this->tickets->updateMeta($ticket, $data, auth()->user());

        return back()->with('status', 'اولویت / دپارتمان به‌روزرسانی شد.');
    }

    public function guestMessage(Request $request)
    {
        $data = $request->validate([
            'body' => 'required|string|max:3000',
            'name' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:160',
            'ticket_id' => 'nullable|integer|exists:tickets,id',
            'submission_key' => 'required|uuid',
        ]);

        $user = auth()->user();
        $widgetScopeToken = session('support_widget_submission_scope');
        if (! is_string($widgetScopeToken) || ! Str::isUuid($widgetScopeToken)) {
            $widgetScopeToken = (string) Str::uuid();
            session(['support_widget_submission_scope' => $widgetScopeToken]);
        }
        $widgetSubmissionScope = 'widget:'.$widgetScopeToken;

        if ($user) {
            $this->tickets->claimGuestTickets($user);

            // A lost response to the very first widget request has no ticket_id
            // in the browser yet. Resolve its original creation key before
            // falling back to the user's latest active widget conversation.
            $submissionScope = hash('sha256', $widgetSubmissionScope);
            $submittedMessage = TicketMessage::query()
                ->where('submission_scope', $submissionScope)
                ->where('submission_key', strtolower($data['submission_key']))
                ->first();
            if ($submittedMessage) {
                $ticket = $submittedMessage->ticket;
                abort_unless($ticket && (int) $ticket->user_id === (int) $user->id, 404);
                $this->tickets->addMessage($ticket, $user, $data['body'], [
                    'is_staff' => false,
                    '_submission_scope' => $widgetSubmissionScope,
                    '_submission_key' => $data['submission_key'],
                ]);

                return response()->json([
                    'ok' => true,
                    'ticket_id' => $ticket->id,
                    'reply' => 'پیام شما ثبت شد. تاریخچه در داشبورد → پشتیبانی.',
                    'redirect' => route('support.show', $ticket),
                ]);
            }

            $submittedTicket = Ticket::query()
                ->where('submission_scope', $submissionScope)
                ->where('submission_key', strtolower($data['submission_key']))
                ->first();
            if ($submittedTicket) {
                $replayData = [
                    'subject' => Str::limit($data['body'], 80),
                    'body' => $data['body'],
                    'source' => SupportTicketService::SOURCE_WIDGET,
                    'department' => SupportTicketService::DEPARTMENT_SUPPORT,
                    'priority' => SupportTicketService::PRIORITY_NORMAL,
                    '_submission_scope' => $widgetSubmissionScope,
                    '_submission_key' => $data['submission_key'],
                ];
                if (filled($submittedTicket->guest_email)) {
                    $replayData['guest_name'] = $submittedTicket->guest_name;
                    $replayData['guest_email'] = $submittedTicket->guest_email;
                }
                $ticket = $this->tickets->createTicket($user, $replayData);

                return response()->json([
                    'ok' => true,
                    'ticket_id' => $ticket->id,
                    'reply' => 'پیام شما ثبت شد. تاریخچه در داشبورد → پشتیبانی.',
                    'redirect' => route('support.show', $ticket),
                ]);
            }

            $ticket = null;
            if (! empty($data['ticket_id'])) {
                $ticket = Ticket::where('id', $data['ticket_id'])->where('user_id', $user->id)->where('status', '!=', 'closed')->first();
            }
            if (! $ticket) {
                $ticket = Ticket::where('user_id', $user->id)->where('status', '!=', 'closed')
                    ->when(Schema::hasColumn('tickets', 'source'), fn ($q) => $q->where('source', SupportTicketService::SOURCE_WIDGET))
                    ->latest()->first();
            }
            if (! $ticket) {
                $ticket = $this->tickets->createTicket($user, [
                    'subject' => Str::limit($data['body'], 80),
                    'body' => $data['body'],
                    'source' => SupportTicketService::SOURCE_WIDGET,
                    'department' => SupportTicketService::DEPARTMENT_SUPPORT,
                    'priority' => SupportTicketService::PRIORITY_NORMAL,
                    '_submission_scope' => $widgetSubmissionScope,
                    '_submission_key' => $data['submission_key'],
                ]);
            } else {
                $this->tickets->addMessage($ticket, $user, $data['body'], [
                    'is_staff' => false,
                    '_submission_scope' => $widgetSubmissionScope,
                    '_submission_key' => $data['submission_key'],
                ]);
            }

            return response()->json([
                'ok' => true,
                'ticket_id' => $ticket->id,
                'reply' => 'پیام شما ثبت شد. تاریخچه در داشبورد → پشتیبانی.',
                'redirect' => route('support.show', $ticket),
            ]);
        }

        $email = mb_strtolower(trim((string) ($data['email'] ?? session('support_guest_email', ''))));
        $name = $data['name'] ?? session('support_guest_name');
        if (! $email) {
            return response()->json(['ok' => false, 'need_contact' => true, 'message' => 'برای ادامه لطفاً نام و ایمیل خود را وارد کنید.'], 422);
        }

        session(['support_guest_email' => $email, 'support_guest_name' => $name]);

        // A matching email and a guessable ticket ID are not authentication.
        // Only continue guest tickets previously issued to this server-side session.
        $sessionTicketIds = collect(session('support_guest_ticket_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
        $requestedTicketId = (int) ($data['ticket_id'] ?? 0);
        $ticket = null;

        $guestSubmissionScope = hash('sha256', $widgetSubmissionScope);
        $submittedMessage = TicketMessage::query()
            ->where('submission_scope', $guestSubmissionScope)
            ->where('submission_key', strtolower($data['submission_key']))
            ->first();
        if ($submittedMessage) {
            $ticket = $submittedMessage->ticket;
            abort_unless(
                $ticket && $ticket->isGuest()
                && hash_equals((string) $ticket->guest_email, $email),
                404
            );
            $this->tickets->addMessage($ticket, null, $data['body'], [
                'is_staff' => false,
                '_submission_scope' => $widgetSubmissionScope,
                '_submission_key' => $data['submission_key'],
            ]);

            return response()->json([
                'ok' => true,
                'ticket_id' => $ticket->id,
                'reply' => 'پیام شما ثبت شد. برای پیگیری، همین گفتگو را ادامه دهید.',
            ]);
        }

        if ($requestedTicketId > 0 && $sessionTicketIds->contains($requestedTicketId)) {
            $ticket = $this->tickets->findOpenGuestTicket($email, $requestedTicketId);
        }

        if (! $ticket) {
            $ticket = $this->tickets->createTicket(null, [
                'subject' => Str::limit($data['body'], 80),
                'body' => $data['body'],
                'guest_name' => $name,
                'guest_email' => $email,
                'source' => SupportTicketService::SOURCE_WIDGET,
                'department' => SupportTicketService::DEPARTMENT_SUPPORT,
                'priority' => SupportTicketService::PRIORITY_NORMAL,
                '_submission_scope' => $widgetSubmissionScope,
                '_submission_key' => $data['submission_key'],
            ]);

            $sessionTicketIds->push((int) $ticket->id);
            session(['support_guest_ticket_ids' => $sessionTicketIds->unique()->take(-10)->values()->all()]);
        } else {
            $this->tickets->addMessage($ticket, null, $data['body'], [
                'is_staff' => false,
                '_submission_scope' => $widgetSubmissionScope,
                '_submission_key' => $data['submission_key'],
            ]);
        }

        return response()->json([
            'ok' => true,
            'ticket_id' => $ticket->id,
            'reply' => 'پیام شما ثبت شد. پس از ورود، ادامه گفتگو در داشبورد → پشتیبانی در دسترس است.',
        ]);
    }

    public function attachment(Request $request, Ticket $ticket, TicketMessage $message, int $index, StorageManager $storage, AuthorizationService $authorization)
    {
        $this->authorize('view', $ticket);
        abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);
        abort_if($message->is_internal && (
            ! app(AdminConsoleService::class)->sectionAllowed($request->user(), 'support_inbox')
            || ! $authorization->allowsRecord($request->user(), 'support.manage', $ticket)
        ), 404);

        $attachments = array_values((array) ($message->attachments ?? []));
        abort_unless(isset($attachments[$index]) && is_array($attachments[$index]), 404);

        $attachment = $attachments[$index];
        abort_if(! empty($attachment['quarantined']), 404);
        abort_unless(! empty($attachment['managed_file_id']), 404);

        $path = (string) ($attachment['path'] ?? '');
        abort_unless($path !== '', 404);
        $diskName = (string) ($attachment['disk'] ?? 'private');
        $isManagedVolume = preg_match('/^storage_volume:[0-9]+$/', $diskName) === 1;
        abort_unless($diskName === 'private' || $isManagedVolume, 404);

        // Every support attachment must be a live private managed object
        // bound to this exact message. Legacy/unmanaged files fail closed.
        $managed = StorageManagedFile::query()
            ->whereKey((int) $attachment['managed_file_id'])
            ->where('source_module', 'support')
            ->where('source_type', TicketMessage::class)
            ->where('source_id', $message->id)
            ->where('visibility', 'private')
            ->whereIn('status', ['active', 'pending_transfer'])
            ->first();

        $expectedDisk = $managed?->storage_volume_id
            ? 'storage_volume:'.$managed->storage_volume_id
            : 'private';

        abort_unless(
            $managed
            && $managed->file_key === $path
            && (string) data_get($managed->metadata, 'disk', $expectedDisk) === $diskName
            && $expectedDisk === $diskName,
            404
        );

        $filename = basename((string) ($attachment['name'] ?? 'attachment'));
        $filename = preg_replace('/[\r\n"]+/', '_', $filename) ?: 'attachment';
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
        ];

        if ($isManagedVolume) {
            abort_unless($storage->exists($diskName, $path), 404);
            return response($storage->get($diskName, $path), 200, $headers);
        }

        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);
        return $disk->download($path, $filename, $headers);
    }

    /**
     * Load a bounded, newest-first slice of a ticket conversation.
     * The cursor is based on all messages so hidden internal notes cannot trap
     * customer pagination or cause repeated polling scans.
     *
     * @return array{messages: \Illuminate\Support\Collection, last_scanned_id: int, oldest_scanned_id: int, has_older: bool}
     */
    private function initialThreadHistory(Ticket $ticket, bool $includeInternal): array
    {
        $scanned = $ticket->messages()
            ->with('user')
            ->reorder('id', 'desc')
            ->limit(self::THREAD_HISTORY_PAGE_SIZE)
            ->get();

        $lastScannedId = (int) ($scanned->first()?->id ?? 0);
        $oldestScannedId = (int) ($scanned->last()?->id ?? 0);
        $hasOlder = $oldestScannedId > 0
            && $ticket->messages()->where('id', '<', $oldestScannedId)->exists();

        if (! $includeInternal) {
            $scanned = $scanned->reject(fn ($message) => (bool) $message->is_internal);
        }

        return [
            'messages' => $scanned->reverse()->values(),
            'last_scanned_id' => $lastScannedId,
            'oldest_scanned_id' => $oldestScannedId,
            'has_older' => $hasOlder,
        ];
    }

    public static function claimGuestTickets(User $user): int
    {
        return app(SupportTicketService::class)->claimGuestTickets($user);
    }

    private function parseInboxDateFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof Carbon && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

}
