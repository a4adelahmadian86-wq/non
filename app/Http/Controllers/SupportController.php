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
        $statusFilter = $request->input('status');
        if (is_string($statusFilter)
            && $statusFilter !== 'all'
            && in_array($statusFilter, array_merge(['needs_reply'], SupportTicketService::STATUSES), true)) {
            if ($statusFilter === 'needs_reply') {
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
            && in_array($request->input('source'), ['dashboard', 'widget'], true)) {
            $query->where('source', $request->input('source'));
        }
        if (Schema::hasColumn('tickets', 'topic_area')
            && in_array($request->input('topic_area'), ['services', 'store'], true)) {
            $query->where('topic_area', $request->input('topic_area'));
        }

        $assignedFilter = $request->input('assigned');
        if (Schema::hasColumn('tickets', 'assigned_to')) {
            if ($assignedFilter === 'me') {
                $query->where('assigned_to', auth()->id());
            } elseif ($assignedFilter === 'unassigned') {
                $query->whereNull('assigned_to');
            }
        }

        $slaFilter = $request->input('sla');
        if ($slaFilter === 'breached') {
            $query->slaBreached();
        }
        if ($slaFilter === 'at_risk') {
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
            ->orderByRaw("CASE WHEN priority = 'high' THEN 0 WHEN priority = 'normal' THEN 1 ELSE 2 END")
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
            ? collect()
            : collect($visibleUserIds)->map(fn ($id) => (int) $id)->unique();

        $auditUserLabels = collect();
        foreach ($auditUsers as $auditUser) {
            $isVisibleTeamSupportAgent = $visibleUserIds !== null
                && $visibleAuditUserIds->contains((int) $auditUser->id)
                && app(AuthorizationService::class)->allows($auditUser, 'support.manage');

            if ((int) $auditUser->id === (int) auth()->id()) {
                $auditUserLabels[$auditUser->id] = $auditUser->name;
            } elseif ($active && (int) $auditUser->id === (int) $active->user_id) {
                $auditUserLabels[$auditUser->id] = 'مشتری';
            } elseif (
                app(AuthorizationService::class)->allowsRecord(auth()->user(), 'support.manage', $auditUser)
                || $isVisibleTeamSupportAgent
            ) {
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

        return view('admin.support', compact('tickets', 'active', 'stats', 'canned', 'agents', 'activeHistory', 'auditEvents', 'auditUserLabels'));
    }

    public function adminReply(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:8000',
            'status' => 'nullable|in:'.implode(',', SupportTicketService::STATUSES),
            'is_internal' => 'nullable|boolean',
            'attachment' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,gif,pdf,txt,zip,doc,docx',
        ]);

        $isInternal = (bool) ($data['is_internal'] ?? false);

        $this->tickets->addMessage($ticket, auth()->user(), $data['body'], [
            'is_staff' => true,
            'is_internal' => $isInternal,
            'status' => $data['status'] ?? 'answered',
            'attachment' => $request->file('attachment'),
            'touch_status' => ! $isInternal || ! empty($data['status']),
        ]);

        return redirect()
            ->route('admin.support.management', ['ticket' => $ticket->id] + $request->only(['status', 'q', 'department', 'priority']))
            ->with('status', $isInternal ? 'یادداشت داخلی ثبت شد.' : 'پاسخ ثبت شد.');
    }

    public function adminStatus(Request $request, Ticket $ticket)
    {
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', SupportTicketService::STATUSES),
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
        ]);

        $user = auth()->user();

        if ($user) {
            $this->tickets->claimGuestTickets($user);

            $ticket = null;
            if (! empty($data['ticket_id'])) {
                $ticket = Ticket::where('id', $data['ticket_id'])->where('user_id', $user->id)->where('status', '!=', 'closed')->first();
            }
            if (! $ticket) {
                $ticket = Ticket::where('user_id', $user->id)->where('status', '!=', 'closed')
                    ->when(Schema::hasColumn('tickets', 'source'), fn ($q) => $q->where('source', 'widget'))
                    ->latest()->first();
            }
            if (! $ticket) {
                $ticket = $this->tickets->createTicket($user, [
                    'subject' => Str::limit($data['body'], 80),
                    'body' => $data['body'],
                    'source' => 'widget',
                    'department' => 'support',
                    'priority' => 'normal',
                ]);
            } else {
                $this->tickets->addMessage($ticket, $user, $data['body'], ['is_staff' => false]);
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

        if ($requestedTicketId > 0 && $sessionTicketIds->contains($requestedTicketId)) {
            $ticket = $this->tickets->findOpenGuestTicket($email, $requestedTicketId);
        }

        if (! $ticket) {
            $ticket = $this->tickets->createTicket(null, [
                'subject' => Str::limit($data['body'], 80),
                'body' => $data['body'],
                'guest_name' => $name,
                'guest_email' => $email,
                'source' => 'widget',
                'department' => 'support',
                'priority' => 'normal',
            ]);

            $sessionTicketIds->push((int) $ticket->id);
            session(['support_guest_ticket_ids' => $sessionTicketIds->unique()->take(-10)->values()->all()]);
        } else {
            $this->tickets->addMessage($ticket, null, $data['body'], ['is_staff' => false]);
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
        abort_if($message->is_internal && ! $authorization->allowsRecord($request->user(), 'support.manage', $ticket), 404);

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
        } catch (\\Throwable) {
            return null;
        }

        return $date instanceof Carbon && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

}
