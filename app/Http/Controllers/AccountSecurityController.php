<?php

namespace App\\Http\\Controllers;

use App\\Models\\EditorSession;
use App\\Models\\User;
use App\\Services\\NotificationService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Str;
use Illuminate\\Support\\Facades\\Crypt;
use Illuminate\\Support\\Facades\\DB;

class AccountSecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currentKey = hash('sha256', $request->session()->getId());
        $cutoff = now()->subYears(3);

        $sessions = DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('last_activity', '>=', $cutoff)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($currentKey) {
                $session->is_current = hash_equals($currentKey, $session->session_key);
                $session->browser_label = $this->browserLabel((string) $session->user_agent);
                return $session;
            });

        $blockedDevices = DB::table('farast_blocked_devices')
            ->where('user_id', $user->id)
            ->whereNull('unblocked_at')
            ->orderByDesc('blocked_at')
            ->get();

        $events = DB::table('farast_security_events')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(80)
            ->get();

        return view('platform.security', [
            'sessions' => $sessions,
            'blockedDevices' => $blockedDevices,
            'events' => $events,
            'sessionLifetimeYears' => 3,
        ]);
    }

    public function revoke(Request $request, int $session)
    {
        $this->confirmPassword($request);
        $record = DB::table('farast_account_sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->first();

        abort_unless($record, 404);
        abort_if(hash_equals(hash('sha256', $request->session()->getId()), $record->session_key), 422, 'برای خروج از نشست فعلی از دکمه خروج استفاده کنید.');

        $this->revokeRecord($record);
        $this->recordEvent($request, 'session_revoked', ['device' => $record->device_label]);
        $this->notify($request->user(), 'security_session_revoked', 'یک نشست از حساب شما خارج شد', 'یک نشست از مرکز امنیت حساب شما لغو شد.', ['url' => route('platform.security')]);

        return back()->with('status', 'نشست انتخاب‌شده لغو شد؛ دسترسی آن نشست در درخواست بعدی قطع می‌شود.');
    }

    public function blockDevice(Request $request, int $session)
    {
        $this->confirmPassword($request);
        $record = DB::table('farast_account_sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->first();

        abort_unless($record, 404);
        if (blank($record->device_key_hash)) {
            return back()->withErrors(['device' => 'شناسه امن این دستگاه موجود نیست؛ ابتدا آن را از حساب خارج کنید تا در ورود بعدی با شناسه امن ثبت شود.']);
        }

        $now = now();
        DB::table('farast_blocked_devices')->updateOrInsert(
            ['user_id' => $request->user()->id, 'device_key_hash' => $record->device_key_hash],
            [
                'device_label' => $record->device_label,
                'ip_address' => $record->ip_address,
                'user_agent' => $record->user_agent,
                'blocked_at' => $now,
                'unblocked_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('farast_account_sessions')
            ->where('user_id', $request->user()->id)
            ->where('device_key_hash', $record->device_key_hash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        $this->recordEvent($request, 'device_blocked', ['device' => $record->device_label]);
        $this->notify($request->user(), 'security_device_blocked', 'دستگاه از ورود به حساب منع شد', 'دستگاه انتخاب‌شده از نشست‌های فعال خارج شد و تا زمانی که آن را از فهرست دستگاه‌های مسدودشده آزاد نکنید، ورود آن با شناسه همین مرورگر پذیرفته نمی‌شود.', ['url' => route('platform.security')]);

        if (hash_equals(hash('sha256', $request->session()->getId()), $record->session_key)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('warning', 'این دستگاه مسدود شد و از حساب خارج شدید.');
        }

        return back()->with('status', 'دستگاه مسدود شد و نشست‌های ثبت‌شده آن لغو شدند.');
    }

    public function unblockDevice(Request $request, int $device)
    {
        $this->confirmPassword($request);
        $record = DB::table('farast_blocked_devices')
            ->where('id', $device)
            ->where('user_id', $request->user()->id)
            ->whereNull('unblocked_at')
            ->first();

        abort_unless($record, 404);
        DB::table('farast_blocked_devices')->where('id', $record->id)->update([
            'unblocked_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordEvent($request, 'device_unblocked', ['device' => $record->device_label]);
        $this->notify($request->user(), 'security_device_unblocked', 'محدودیت یک دستگاه برداشته شد', 'دستگاه انتخاب‌شده دوباره اجازه تلاش برای ورود به حساب را دارد.', ['url' => route('platform.security')]);

        return back()->with('status', 'محدودیت دستگاه برداشته شد.');
    }

    public function revokeOthers(Request $request)
    {
        $this->confirmPassword($request);
        $currentKey = hash('sha256', $request->session()->getId());
        $others = DB::table('farast_account_sessions')
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('session_key', '!=', $currentKey)
            ->get();

        foreach ($others as $record) {
            $this->revokeRecord($record);
        }

        if ($others->isNotEmpty()) {
            EditorSession::where('user_id', $request->user()->id)->delete();
        }

        $this->recordEvent($request, 'other_sessions_revoked', ['count' => $others->count()]);
        $this->notify($request->user(), 'security_other_sessions_revoked', 'نشست‌های دیگر بسته شدند', 'تمام نشست‌های دیگر حساب شما لغو شدند؛ نشست فعلی باز است.', ['url' => route('platform.security')]);

        return back()->with('status', $others->count().' نشست دیگر لغو شد. نشست فعلی باز می‌ماند.');
    }

    public function changePassword(Request $request, \\App\\Services\\EmailService $emailService)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'confirmed', 'different:current_password'],
        ], [
            'password.min' => 'رمز عبور جدید باید حداقل ۱۲ نویسه داشته باشد.',
            'password.confirmed' => 'تکرار رمز عبور با رمز جدید یکسان نیست.',
            'password.different' => 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.',
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        $this->rotateRememberToken($user);

        $currentSessionId = $request->session()->getId();
        $currentKey = hash('sha256', $currentSessionId);
        DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->where('session_key', '!=', $currentKey)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete();
        EditorSession::where('user_id', $user->id)->delete();

        $this->recordEvent($request, 'password_changed', ['method' => 'authenticated_account_security']);
        $this->notify($user, 'security_password_changed', 'رمز عبور حساب شما تغییر کرد', 'رمز عبور حساب فراست تغییر کرد و نشست سایر دستگاه‌ها بسته شد. اگر این کار توسط شما انجام نشده، حساب را بررسی کنید.', ['url' => route('platform.security')]);

        try {
            $emailService->sendPasswordChanged($user);
        } catch (\\Throwable) {
        }

        return back()->with('status', 'رمز عبور تغییر کرد؛ نشست سایر دستگاه‌ها لغو شدند.');
    }

    public function revokeAll(Request $request)
    {
        $this->confirmPassword($request);
        $all = DB::table('farast_account_sessions')
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($all as $record) {
            $this->revokeRecord($record);
        }

        $this->rotateRememberToken($request->user());
        EditorSession::where('user_id', $request->user()->id)->delete();
        $this->recordEvent($request, 'all_sessions_revoked', ['count' => $all->count()]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'خروج از همه دستگاه‌ها انجام شد. برای استفاده دوباره وارد شوید.');
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
    }

    private function rotateRememberToken(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();
    }

    private function revokeRecord(object $record): void
    {
        DB::table('farast_account_sessions')
            ->where('id', $record->id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        try {
            $sessionId = Crypt::decryptString($record->session_id_encrypted);
            DB::table('sessions')->where('id', $sessionId)->delete();
        } catch (\\Throwable) {
            // The revocation marker also supports non-database session drivers.
        }
    }

    private function recordEvent(Request $request, string $event, array $meta = []): void
    {
        DB::table('farast_security_events')->insert([
            'user_id' => $request->user()->id,
            'event' => $event,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 4000),
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function notify(User $user, string $type, string $title, string $body, array $data = []): void
    {
        try {
            app(NotificationService::class)->pushToUser($user, $type, $title, $body, $data);
        } catch (\\Throwable) {
            // Security actions must remain successful if the notification store is temporarily unavailable.
        }
    }

    private function browserLabel(string $agent): string
    {
        return preg_match('/Edg\\//i', $agent) ? 'Microsoft Edge'
            : (preg_match('/Firefox\\//i', $agent) ? 'Mozilla Firefox'
            : (preg_match('/Chrome\\//i', $agent) ? 'Google Chrome'
            : (preg_match('/Safari\\//i', $agent) ? 'Safari' : 'مرورگر دیگر')));
    }
}
