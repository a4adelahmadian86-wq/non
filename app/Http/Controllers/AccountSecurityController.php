<?php

namespace App\Http\Controllers;

use App\Models\EditorSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AccountSecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currentKey = hash('sha256', $request->session()->getId());
        $cutoff = now()->subMinutes((int) config('session.lifetime', 120));

        $sessions = DB::table('farast_account_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('last_activity', '>=', $cutoff)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($currentKey) {
                $session->is_current = hash_equals($currentKey, $session->session_key);
                return $session;
            });

        $events = DB::table('farast_security_events')
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return view('platform.security', [
            'sessions' => $sessions,
            'events' => $events,
            'sessionLifetime' => (int) config('session.lifetime', 120),
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
        $this->rotateRememberToken($request->user());
        $this->recordEvent($request, 'session_revoked', ['device' => $record->device_label]);

        return back()->with('status', 'نشست انتخاب‌شده برای خروج امن علامت‌گذاری شد.');
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
            $this->rotateRememberToken($request->user());
        }

        $this->recordEvent($request, 'other_sessions_revoked', ['count' => $others->count()]);

        return back()->with('status', $others->count().' نشست دیگر برای خروج امن علامت‌گذاری شد. نشست فعلی باز می‌ماند.');
    }

    public function changePassword(Request $request, \App\Services\EmailService $emailService)
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

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        EditorSession::where('user_id', $user->id)->delete();

        $this->recordEvent($request, 'password_changed', ['method' => 'authenticated_account_security']);

        try {
            app(\App\Services\NotificationService::class)->pushToUser(
                $user,
                'security_password_changed',
                'رمز عبور حساب شما تغییر کرد',
                'رمز عبور حساب فراست تغییر کرد. نشست سایر دستگاه‌ها بسته شد. اگر این کار توسط شما انجام نشده، حساب را فوراً بررسی کنید.',
                ['url' => route('platform.security')],
            );
        } catch (\Throwable) {
        }

        try {
            $emailService->sendPasswordChanged($user);
        } catch (\Throwable) {
        }

        return back()->with('status', 'رمز عبور تغییر کرد؛ نشست سایر دستگاه‌ها و ورودهای ماندگار قبلی لغو شدند.');
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
        // Laravel stores one remember token per account. Rotating it prevents a
        // revoked browser from silently signing back in with an old recaller cookie.
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
        } catch (\Throwable) {
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
            'updated_at' => now(),
        ]);
    }
}
