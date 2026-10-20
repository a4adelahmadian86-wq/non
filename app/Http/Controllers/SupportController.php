<?php

namespace App\Http\Controllers;

use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CapabilityService;
use App\Services\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
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

        $ticket->load(['publicMessages.user', 'relatedProduct', 'assignee']);
        $this->tickets->markCustomerSeen($ticket);
        $ticket->setRelation('messages', $ticket->publicMessages);

        return view('support.show', compact('ticket'));
    }

    public function pollMessages(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support') || auth()->user()->isAdmin(), 403);
        $this->authorize('view', $ticket);

        $afterId = (int) $request->input('after_id', 0);
        $isAdmin = auth()->user()->isAdmin();

        $messages = $ticket->messages()
            ->where('id', '>', $afterId)
            ->when(! $isAdmin, fn ($q) => $q->where(function ($w) {
                $w->where('is_internal', false)->orWhereNull('is_internal');
            }))
            ->orderBy('created_at')
            ->get()
            ->map(function ($m) {
                $isStaff = $m->isFromStaff();

                return [
                    'id' => $m->id,
                    'body' => $m->body,
                    'is_staff' => $isStaff,
                    'is_internal' => (bool) $m->is_internal,
                    'is_mine' => $m->user_id === auth()->id() && ! $isStaff,
                    'time' => $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i'),
                    'attachments' => $m->attachments ?? [],
                ];
            });

        if (! $isAdmin) {
            $this->tickets->markCustomerSeen($ticket);
        }

        return response()->json([
            'ok' => true,
            'messages' => $messages,
            'status' => $ticket->fresh()->status,
            'status_label' => $ticket->fresh()->statusLabel(),
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
            'topic_area' => 'required|string|in:services,store',
            'related_service' => [Rule::requiredIf(fn () => $request->input('topic_area') === 'services'), 'nullable', 'string', 'max:80'],
            'related_product_id' => [Rule::requiredIf(fn () => $request->input('topic_area') === 'store'), 'nullable', 'integer', Rule::exists('store_products', 'id')],
            'attachment' => 'nullable|file|max:5120|mimes:txt,jpg,jpeg,pdf,png',
        ], [
            'department.required' => 'دپارتمان را انتخاب کنید.',
            'priority.required' => 'اولویت را انتخاب کنید.',
            'topic_area.required' => 'حوزه موضوع را انتخاب کنید.',
            'related_service.required' => 'نوع خدمت را انتخاب کنید.',
            'related_product_id.required' => 'یک محصول مرتبط انتخاب کنید.',
        ]);

        $body = $data['body'];
        $meta = [];
        if ($data['topic_area'] === 'services' && ! empty($data['related_service'])) {
            $labels = ['typing' => 'تایپ آنلاین', 'convert' => 'تبدیل فایل', 'edit' => 'ویرایش هوشمند', 'ocr' => 'OCR / استخراج متن', 'voice' => 'ورود صوتی', 'other' => 'سایر خدمات'];
            $meta[] = 'حوزه: خدمات — '.($labels[$data['related_service']] ?? $data['related_service']);
        }
        if ($data['topic_area'] === 'store' && ! empty($data['related_product_id'])) {
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
            'related_service' => $data['topic_area'] === 'services' ? ($data['related_service'] ?? null) : null,
            'related_product_id' => $data['topic_area'] === 'store' ? ($data['related_product_id'] ?? null) : null,
            'source' => 'dashboard',
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
        ]);

        $msg = $this->tickets->addMessage($ticket, auth()->user(), $data['body'], [
            'is_staff' => false,
            'attachment' => $request->file('attachment'),
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
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        try {
            $this->tickets->submitCsat($ticket, (int) $data['score'], $data['comment'] ?? null);
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

    public function adminIndex(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $query = Ticket::with([
            'messages' => fn ($q) => $q->orderBy('created_at'),
            'user', 'relatedProduct', 'assignee',
        ]);

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'needs_reply') {
                $query->needsReply();
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->filled('department') && $request->department !== 'all') {
            $query->where('department', $request->department);
        }
        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned') && $request->assigned === 'me') {
            $query->where('assigned_to', auth()->id());
        }
        if ($request->filled('sla') && $request->sla === 'breached') {
            $query->slaBreached();
        }
        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', '%'.$q.'%')
                    ->orWhere('id', $q)
                    ->orWhere('ticket_code', 'like', '%'.$q.'%')
                    ->orWhere('guest_email', 'like', '%'.$q.'%')
                    ->orWhere('guest_name', 'like', '%'.$q.'%');
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
        if ($request->filled('ticket')) {
            $active = Ticket::with(['messages' => fn ($q) => $q->orderBy('created_at'), 'user', 'relatedProduct', 'assignee'])
                ->find($request->integer('ticket'));
        }
        if (! $active) {
            $active = $tickets->getCollection()->first();
        }

        $stats = [
            'needs_reply' => Ticket::needsReply()->count(),
            'open' => Ticket::where('status', 'open')->count(),
            'user_replied' => Ticket::where('status', 'user_replied')->count(),
            'answered' => Ticket::where('status', 'answered')->count(),
            'pending' => Ticket::where('status', 'pending')->count(),
            'closed' => Ticket::where('status', 'closed')->count(),
            'high' => Ticket::where('priority', 'high')->whereNotIn('status', ['closed'])->count(),
        
            'sla_breached' => Ticket::query()->slaBreached()->count(),
        ];

        $agents = User::query()->where('role', User::ROLE_ADMIN)->limit(40)->get(['id', 'name', 'email', 'mobile']);

        $canned = [
            'سلام، پیام شما دریافت شد. در حال بررسی هستیم و به‌زودی پاسخ می‌دهیم.',
            'برای پیگیری دقیق‌تر لطفاً اسکرین‌شات یا جزئیات بیشتری ارسال کنید.',
            'مشکل بررسی و برطرف شد. لطفاً مجدداً امتحان کنید و نتیجه را اعلام بفرمایید.',
            'با تشکر از صبوری شما. تیکت بسته شد. در صورت نیاز تیکت جدید ثبت کنید.',
        ];

        return view('admin.support', compact('tickets', 'active', 'stats', 'canned', 'agents'));
    }

    public function adminReply(Request $request, Ticket $ticket)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
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
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->authorize('manage', $ticket);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', SupportTicketService::STATUSES),
        ]);

        $this->tickets->changeStatus($ticket, $data['status'], auth()->user(), true);

        return back()->with('status', 'وضعیت تیکت به‌روزرسانی شد.');
    }

    public function adminAssign(Request $request, Ticket $ticket)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $agent = ! empty($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        $this->tickets->assign($ticket, $agent, auth()->user());

        return back()->with('status', 'ارجاع تیکت ذخیره شد.');
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

        $email = $data['email'] ?? session('support_guest_email');
        $name = $data['name'] ?? session('support_guest_name');
        if (! $email) {
            return response()->json(['ok' => false, 'need_contact' => true, 'message' => 'برای ادامه لطفاً نام و ایمیل خود را وارد کنید.'], 422);
        }
        session(['support_guest_email' => $email, 'support_guest_name' => $name]);

        $ticket = $this->tickets->findOpenGuestTicket($email, $data['ticket_id'] ?? null);
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
        } else {
            $this->tickets->addMessage($ticket, null, $data['body'], ['is_staff' => false]);
        }

        return response()->json([
            'ok' => true,
            'ticket_id' => $ticket->id,
            'reply' => 'پیام شما ثبت شد. پس از ورود، ادامه گفتگو در داشبورد → پشتیبانی در دسترس است.',
        ]);
    }

    public static function claimGuestTickets(User $user): int
    {
        return app(SupportTicketService::class)->claimGuestTickets($user);
    }
}
