<?php

namespace App\Http\Controllers;

use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CapabilityService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    /** لیست تیکت‌ها — جدول مشابه ران‌فلر */
    public function index(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403, 'پشتیبانی برای این حساب فعال نیست.');

        self::claimGuestTickets(auth()->user());

        $query = auth()->user()->tickets();

        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', '%'.$q.'%')
                    ->orWhere('id', $q);
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

    /** فرم ثبت تیکت جدید */
    public function createForm(CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);

        $purchasedProducts = collect();
        if (Schema::hasTable('store_library_items')) {
            $purchasedProducts = StoreLibraryItem::query()
                ->where('user_id', auth()->id())
                ->whereNull('revoked_at')
                ->with('product:id,title,slug')
                ->latest('granted_at')
                ->get()
                ->filter(fn ($item) => $item->product)
                ->unique('product_id')
                ->take(5)
                ->values()
                ->map(fn ($item) => $item->product);
        }

        $catalogProducts = collect();
        if (Schema::hasTable('store_products')) {
            $catalogProducts = StoreProduct::query()
                ->published()
                ->orderBy('title')
                ->limit(80)
                ->get(['id', 'title', 'slug']);
        }

        $services = [
            'typing' => 'تایپ آنلاین',
            'convert' => 'تبدیل فایل',
            'edit' => 'ویرایش هوشمند',
            'ocr' => 'OCR / استخراج متن',
            'voice' => 'ورود صوتی',
            'other' => 'سایر خدمات',
        ];

        return view('support.create', compact('purchasedProducts', 'catalogProducts', 'services'));
    }

    /** جزئیات گفتگوی یک تیکت */
    public function show(Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        abort_unless($ticket->user_id === auth()->id(), 403);

        $ticket->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return view('support.show', compact('ticket'));
    }

    public function create(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);

        $data = $request->validate([
            'subject' => 'required|string|max:160',
            'body' => 'required|string|max:5000',
            'department' => 'required|string|in:support,sales,billing,complaint',
            'priority' => 'required|string|in:low,normal,high',
            'topic_area' => 'required|string|in:services,store',
            'related_service' => [
                Rule::requiredIf(fn () => $request->input('topic_area') === 'services'),
                'nullable',
                'string',
                'max:80',
            ],
            'related_product_id' => [
                Rule::requiredIf(fn () => $request->input('topic_area') === 'store'),
                'nullable',
                'integer',
                Rule::exists('store_products', 'id'),
            ],
            'attachment' => 'nullable|file|max:5120|mimes:txt,jpg,jpeg,pdf,png',
        ], [
            'department.required' => 'دپارتمان را انتخاب کنید.',
            'priority.required' => 'اولویت را انتخاب کنید.',
            'topic_area.required' => 'حوزه موضوع (خدمات یا فروشگاه) را انتخاب کنید.',
            'related_service.required' => 'نوع خدمت را انتخاب کنید.',
            'related_product_id.required' => 'یک محصول مرتبط انتخاب کنید.',
            'subject.required' => 'عنوان تیکت را وارد کنید.',
            'body.required' => 'شرح پیام را بنویسید.',
        ]);

        $payload = [
            'user_id' => auth()->id(),
            'subject' => $data['subject'],
            'status' => 'open',
            'priority' => $data['priority'],
            'department' => $data['department'],
        ];

        if (Schema::hasColumn('tickets', 'topic_area')) {
            $payload['topic_area'] = $data['topic_area'];
        }
        if (Schema::hasColumn('tickets', 'related_service')) {
            $payload['related_service'] = $data['topic_area'] === 'services'
                ? ($data['related_service'] ?? null)
                : null;
        }
        if (Schema::hasColumn('tickets', 'related_product_id')) {
            $payload['related_product_id'] = $data['topic_area'] === 'store'
                ? ($data['related_product_id'] ?? null)
                : null;
        }
        if (Schema::hasColumn('tickets', 'source')) {
            $payload['source'] = 'dashboard';
        }
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $payload['last_reply_at'] = now();
        }

        $ticket = Ticket::create($payload);

        $body = $data['body'];
        // متادیتا در ابتدای پیام برای پشتیبانی
        $metaLines = [];
        if (($data['topic_area'] ?? '') === 'services' && ! empty($data['related_service'])) {
            $labels = [
                'typing' => 'تایپ آنلاین',
                'convert' => 'تبدیل فایل',
                'edit' => 'ویرایش هوشمند',
                'ocr' => 'OCR / استخراج متن',
                'voice' => 'ورود صوتی',
                'other' => 'سایر خدمات',
            ];
            $metaLines[] = 'حوزه: خدمات — '.($labels[$data['related_service']] ?? $data['related_service']);
        }
        if (($data['topic_area'] ?? '') === 'store' && ! empty($data['related_product_id'])) {
            $product = StoreProduct::find($data['related_product_id']);
            $metaLines[] = 'حوزه: فروشگاه — محصول: '.($product?->title ?? ('#'.$data['related_product_id']));
        }
        if ($metaLines) {
            $body = implode("\n", $metaLines)."\n\n".$body;
        }

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => $body,
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
        }

        if ($request->hasFile('attachment') && Schema::hasColumn('ticket_messages', 'attachments')) {
            $path = $request->file('attachment')->store('ticket-attachments/'.$ticket->id, 'public');
            $msgPayload['attachments'] = [[
                'path' => $path,
                'name' => $request->file('attachment')->getClientOriginalName(),
                'size' => $request->file('attachment')->getSize(),
            ]];
        }

        $ticket->messages()->create($msgPayload);

        return redirect()->route('support.show', $ticket)->with('status', 'تیکت شما ثبت شد.');
    }

    public function message(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        abort_unless($ticket->user_id === auth()->id(), 403);
        abort_if($ticket->status === 'closed', 422, 'این تیکت بسته است.');

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'attachment' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,gif,pdf,txt,zip',
        ]);

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
        }

        if ($request->hasFile('attachment') && Schema::hasColumn('ticket_messages', 'attachments')) {
            $path = $request->file('attachment')->store('ticket-attachments/'.$ticket->id, 'public');
            $msgPayload['attachments'] = [[
                'path' => $path,
                'name' => $request->file('attachment')->getClientOriginalName(),
                'size' => $request->file('attachment')->getSize(),
            ]];
        }

        $msg = $ticket->messages()->create($msgPayload);

        $update = ['status' => 'open'];
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $update['last_reply_at'] = now();
        }
        $ticket->update($update);

        if ($request->wantsJson()) {
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
        abort_unless($ticket->user_id === auth()->id(), 403);

        $ticket->update(['status' => 'closed']);

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => '[سیستم] کاربر تیکت را بست.',
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
        }
        $ticket->messages()->create($msgPayload);

        return redirect()->route('support')->with('status', 'تیکت بسته شد.');
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
            self::claimGuestTickets($user);

            $ticket = null;
            if (! empty($data['ticket_id'])) {
                $ticket = Ticket::where('id', $data['ticket_id'])
                    ->where('user_id', $user->id)
                    ->where('status', '!=', 'closed')
                    ->first();
            }

            if (! $ticket) {
                $ticket = Ticket::where('user_id', $user->id)
                    ->where('status', '!=', 'closed')
                    ->when(Schema::hasColumn('tickets', 'source'), fn ($q) => $q->where('source', 'widget'))
                    ->latest()
                    ->first();
            }

            if (! $ticket) {
                $payload = [
                    'user_id' => $user->id,
                    'subject' => Str::limit($data['body'], 80),
                    'status' => 'open',
                    'priority' => 'normal',
                ];
                if (Schema::hasColumn('tickets', 'department')) {
                    $payload['department'] = 'support';
                }
                if (Schema::hasColumn('tickets', 'source')) {
                    $payload['source'] = 'widget';
                }
                if (Schema::hasColumn('tickets', 'last_reply_at')) {
                    $payload['last_reply_at'] = now();
                }
                $ticket = Ticket::create($payload);
            }

            $msgPayload = ['user_id' => $user->id, 'body' => $data['body']];
            if (Schema::hasColumn('ticket_messages', 'is_staff')) {
                $msgPayload['is_staff'] = false;
            }
            $ticket->messages()->create($msgPayload);

            $upd = ['status' => 'open'];
            if (Schema::hasColumn('tickets', 'last_reply_at')) {
                $upd['last_reply_at'] = now();
            }
            $ticket->update($upd);

            return response()->json([
                'ok' => true,
                'ticket_id' => $ticket->id,
                'reply' => 'پیام شما ثبت شد. تیم پشتیبانی به‌زودی پاسخ می‌دهد. تاریخچه در داشبورد → پشتیبانی.',
                'redirect' => route('support.show', $ticket),
            ]);
        }

        $email = $data['email'] ?? session('support_guest_email');
        $name = $data['name'] ?? session('support_guest_name');

        if (! $email) {
            return response()->json([
                'ok' => false,
                'need_contact' => true,
                'message' => 'برای ادامه لطفاً نام و ایمیل خود را وارد کنید.',
            ], 422);
        }

        session([
            'support_guest_email' => $email,
            'support_guest_name' => $name,
        ]);

        $ticket = null;
        if (! empty($data['ticket_id']) && Schema::hasColumn('tickets', 'guest_email')) {
            $ticket = Ticket::where('id', $data['ticket_id'])
                ->whereNull('user_id')
                ->where('guest_email', $email)
                ->whereIn('status', ['open', 'answered', 'pending'])
                ->first();
        }

        if (! $ticket && Schema::hasColumn('tickets', 'guest_email')) {
            $ticket = Ticket::whereNull('user_id')
                ->where('guest_email', $email)
                ->whereIn('status', ['open', 'answered', 'pending'])
                ->latest()
                ->first();
        }

        if (! $ticket) {
            $payload = [
                'user_id' => null,
                'subject' => Str::limit($data['body'], 80),
                'status' => 'open',
                'priority' => 'normal',
            ];
            if (Schema::hasColumn('tickets', 'guest_name')) {
                $payload['guest_name'] = $name;
            }
            if (Schema::hasColumn('tickets', 'guest_email')) {
                $payload['guest_email'] = $email;
            }
            if (Schema::hasColumn('tickets', 'department')) {
                $payload['department'] = 'support';
            }
            if (Schema::hasColumn('tickets', 'source')) {
                $payload['source'] = 'widget';
            }
            if (Schema::hasColumn('tickets', 'last_reply_at')) {
                $payload['last_reply_at'] = now();
            }
            $ticket = Ticket::create($payload);
        }

        $msgPayload = ['user_id' => null, 'body' => $data['body']];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
        }
        $ticket->messages()->create($msgPayload);

        $upd = ['status' => 'open'];
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $upd['last_reply_at'] = now();
        }
        $ticket->update($upd);

        return response()->json([
            'ok' => true,
            'ticket_id' => $ticket->id,
            'reply' => 'پیام شما ثبت شد. پس از ورود، تاریخچه در داشبورد → پشتیبانی در دسترس است.',
        ]);
    }

    public function adminReply(Request $request, Ticket $ticket, EmailService $emailService)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'status' => 'nullable|in:open,answered,closed,pending',
        ]);

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => $data['body'],
            'is_internal' => false,
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = true;
        }

        $ticket->messages()->create($msgPayload);

        $update = ['status' => $data['status'] ?? 'answered'];
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $update['last_reply_at'] = now();
        }
        $ticket->update($update);

        try {
            if ($ticket->user) {
                $emailService->sendTicketReply($ticket->user, $ticket->fresh(), $data['body']);
            }
        } catch (\Throwable) {
        }

        return back()->with('status', 'پاسخ ثبت شد.');
    }

    public static function claimGuestTickets(User $user): int
    {
        if (! Schema::hasColumn('tickets', 'guest_email') || ! $user->email) {
            return 0;
        }

        $email = mb_strtolower(trim($user->email));

        return Ticket::whereNull('user_id')
            ->whereRaw('LOWER(guest_email) = ?', [$email])
            ->update(['user_id' => $user->id]);
    }
}
