<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\CapabilityService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    public function index(CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403, 'پشتیبانی برای این حساب فعال نیست.');

        // ادغام تیکت‌های مهمان در صورت وجود ایمیل
        self::claimGuestTickets(auth()->user());

        $query = auth()->user()->tickets()->with(['messages' => fn ($q) => $q->orderBy('created_at')]);

        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $query->orderByDesc('last_reply_at');
        }

        $tickets = $query->latest()->get();

        return view('support', [
            'tickets' => $tickets,
            'activeTicketId' => request('ticket'),
        ]);
    }

    public function create(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);

        $data = $request->validate([
            'subject' => 'required|string|max:160',
            'body' => 'required|string|max:5000',
            'department' => 'nullable|string|in:support,sales,billing,complaint',
            'priority' => 'nullable|string|in:low,normal,high',
        ]);

        $payload = [
            'user_id' => auth()->id(),
            'subject' => $data['subject'],
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
        ];

        if (Schema::hasColumn('tickets', 'department')) {
            $payload['department'] = $data['department'] ?? 'support';
        }
        if (Schema::hasColumn('tickets', 'source')) {
            $payload['source'] = 'dashboard';
        }
        if (Schema::hasColumn('tickets', 'last_reply_at')) {
            $payload['last_reply_at'] = now();
        }

        $ticket = Ticket::create($payload);

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
        }

        $ticket->messages()->create($msgPayload);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'ticket_id' => $ticket->id,
                'message' => 'تیکت شما ثبت شد.',
            ]);
        }

        return redirect()->route('support', ['ticket' => $ticket->id])
            ->with('status', 'تیکت شما ثبت شد.');
    }

    public function message(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        abort_unless($ticket->user_id === auth()->id(), 403);
        abort_if($ticket->status === 'closed', 422, 'این تیکت بسته است.');

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $msgPayload = [
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ];
        if (Schema::hasColumn('ticket_messages', 'is_staff')) {
            $msgPayload['is_staff'] = false;
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

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => '[سیستم] کاربر تیکت را بست.',
            'is_staff' => false,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('support')->with('status', 'تیکت بسته شد.');
    }

    /**
     * پیام مهمان از ویجت شناور (بدون لاگین).
     */
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
                    ->where('source', 'widget')
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
                'reply' => 'پیام شما ثبت شد. تیم پشتیبانی به‌زودی پاسخ می‌دهد. تاریخچه کامل در داشبورد → پشتیبانی.',
                'redirect' => route('support', ['ticket' => $ticket->id]),
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
        if (! empty($data['ticket_id'])) {
            $ticket = Ticket::where('id', $data['ticket_id'])
                ->where(function ($q) use ($email) {
                    $q->whereNull('user_id')->where('guest_email', $email);
                })
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
            'reply' => 'پیام شما ثبت شد. پس از ورود به حساب، تاریخچه در داشبورد → پشتیبانی در دسترس است.',
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

        return back()->with('status', 'پاسخ ثبت و ایمیل اطلاع‌رسانی ارسال شد.');
    }

    /**
     * ادغام تیکت‌های مهمان با حساب بعد از لاگین.
     */
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
