<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\CapabilityService;
use App\Services\EmailService;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403, 'پشتیبانی برای این حساب فعال نیست.');

        return view('support', [
            'tickets' => auth()->user()->tickets()->with('messages')->latest()->get(),
        ]);
    }

    public function create(Request $request, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);

        $data = $request->validate([
            'subject' => 'required|string|max:160',
            'body' => 'required|string|max:5000',
        ]);

        $ticket = Ticket::create([
            'user_id' => auth()->id(),
            'subject' => $data['subject'],
            'status' => 'open',
        ]);

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        return back()->with('status', 'تیکت شما ثبت شد.');
    }

    public function message(Request $request, Ticket $ticket, CapabilityService $capabilities)
    {
        abort_unless($capabilities->allowed(auth()->user(), 'can_support'), 403);
        abort_unless($ticket->user_id === auth()->id(), 403);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        $ticket->touch();

        return back();
    }

    /**
     * پاسخ مدیر به تیکت (از پنل ادمین).
     */
    public function adminReply(Request $request, Ticket $ticket, EmailService $emailService)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'status' => 'nullable|in:open,answered,closed',
        ]);

        $ticket->messages()->create([
            'user_id' => auth()->id(),
            'body' => $data['body'],
            'is_internal' => false,
        ]);

        $ticket->update([
            'status' => $data['status'] ?? 'answered',
        ]);

        $ticket->touch();

        try {
            if ($ticket->user) {
                $emailService->sendTicketReply($ticket->user, $ticket->fresh(), $data['body']);
            }
        } catch (\Throwable) {
        }

        return back()->with('status', 'پاسخ ثبت و ایمیل اطلاع‌رسانی ارسال شد.');
    }
}
