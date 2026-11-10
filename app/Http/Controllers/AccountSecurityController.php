<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

        $this->recordEvent($request, 'other_sessions_revoked', ['count' => $others->count()]);

        return back()->with('status', $others->count().' نشست دیگر برای خروج امن علامت‌گذاری شد. نشست فعلی باز می‌ماند.');
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
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
