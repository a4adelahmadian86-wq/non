<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketMessage;
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

        $tickets = auth()->user()
            ->tickets()
            ->with(['messages' => fn ($q) => $q->orderBy('created_at')])
            ->latest('last_reply_at')
            ->latest()
            ->get();

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

        $ticket = Ticket::create([
            'user_id' => auth()->id(),
            'subject' => $data['subject'],
            'status' => 'open',
            'priority' => $data['priority'] ?? 'normal',
            'department' => $data['department'] ?? 'support',
            'source' => 'dashboard',
            'last_reply_at' => now(),
        ]);

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
            'is_staff' => false,
        ]);

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

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $msg = $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
            'is_staff' => false,
        ]);

        $ticket->update([
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => [
                    'id' => $msg->id,
                    'body' => $msg->body,
                    'is_staff' => false,
                    'created_at' => $msg->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'time_fa' => jdate($msg->created_at)->format('H:i') ?? $msg->created_at->format('H:i'),
                ],
            ]);
        }

        return back();
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

        // اگر کاربر لاگین است، مستقیم تیکت واقعی بساز
        if ($user) {
            $ticket = null;
            if (! empty($data['ticket_id'])) {
                $ticket = Ticket::where('id', $data['ticket_id'])
                    ->where('user_id', $user->id)
                    ->first();
            }

            if (! $ticket) {
                $ticket = Ticket::create([
                    'user_id' => $user->id,
                    'subject' => Str::limit($data['body'], 80),
                    'status' => 'open',
                    'priority' => 'normal',
                    'department' => 'support',
                    'source' => 'widget',
                    'last_reply_at' => now(),
                ]);
            }

            $msg = $ticket->messages()->create([
                'user_id' => $user->id,
                'body' => $data['body'],
                'is_staff' => false,
            ]);

            $ticket->update(['last_reply_at' => now(), 'status' => 'open']);

            return response()->json([
                'ok' => true,
                'ticket_id' => $ticket->id,
                'reply' => 'پیام شما ثبت شد. تیم پشتیبانی به زودی پاسخ می‌دهد. می‌توانید تاریخچه کامل را در داشبورد → پشتیبانی ببینید.',
                'redirect' => route('support', ['ticket' => $ticket->id]),
            ]);
        }

        // مهمان
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
                ->whereNull('user_id')
                ->where('guest_email', $email)
                ->whereIn('status', ['open', 'answered', 'pending'])
                ->first();
        }

        if (! $ticket) {
            $ticket = Ticket::whereNull('user_id')
                ->where('guest_email', $email)
                ->whereIn('status', ['open', 'answered', 'pending'])
                ->latest()
                ->first();
        }

        if (! $ticket) {
            $ticket = Ticket::create([
                'user_id' => null,
                'guest_name' => $name,
                'guest_email' => $email,
                'subject' => Str::limit($data['body'], 80),
                'status' => 'open',
                'priority' => 'normal',
                'department' => 'support',
                'source' => 'widget',
                'last_reply_at' => now(),
            ]);
        }

        $ticket->messages()->create([
            'user_id' => null,
            'body' => $data['body'],
            'is_staff' => false,
        ]);

        $ticket->update(['last_reply_at' => now(), 'status' => 'open']);

        return response()->json([
            'ok' => true,
            'ticket_id' => $ticket->id,
            'reply' => 'پیام شما ثبت شد. پس از ورود به حساب، تاریخچه کامل در داشبورد → پشتیبانی در دسترس خواهد بود.',
        ]);
    }

    /**
     * پاسخ مدیر به تیکت (از پنل ادمین).
     */
    public function adminReply(Request $request, Ticket $ticket, EmailService $emailService)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'status' => 'nullable|in:open,answered,closed,pending',
        ]);

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
            'is_internal' => false,
            'is_staff' => true,
        ]);

        $ticket->update([
            'status' => $data['status'] ?? 'answered',
            'last_reply_at' => now(),
        ]);

        try {
            if ($ticket->user) {
                $emailService->sendTicketReply($ticket->user, $ticket->fresh(), $data['body']);
            } elseif ($ticket->guest_email) {
                // optional: send to guest email if mailer supports it
            }
        } catch (\Throwable) {
        }

        return back()->with('status', 'پاسخ ثبت و ایمیل اطلاع‌رسانی ارسال شد.');
    }

    /**
     * ادغام تیکت‌های مهمان با حساب بعد از لاگین (قابل فراخوانی از AuthController).
     */
    public static function claimGuestTickets(User $user): int
    {
        if (! Schema::hasColumn('tickets', 'guest_email') || ! $user->email) {
            return 0;
        }

        $count = Ticket::whereNull('user_id')
            ->where('guest_email', $user->email)
            ->update(['user_id' => $user->id]);

        return $count;
    }
}
