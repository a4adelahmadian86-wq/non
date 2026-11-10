<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthenticatedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();
        if ($sessionId === '') {
            return $next($request);
        }

        $sessionKey = hash('sha256', $sessionId);
        $tracked = DB::table('farast_account_sessions')->where('session_key', $sessionKey)->first();

        if ($tracked && $tracked->revoked_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('warning', 'این نشست از بخش امنیت حساب خارج شده است. دوباره وارد شوید.');
        }

        if (! $tracked) {
            $cutoff = now()->subMinutes((int) config('session.lifetime', 120));
            $previous = DB::table('farast_account_sessions')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('last_activity', '>=', $cutoff)
                ->orderByDesc('last_activity')
                ->first();

            $agent = substr((string) $request->userAgent(), 0, 4000);
            $ip = $request->ip();
            $label = $this->deviceLabel($agent);

            DB::table('farast_account_sessions')->insert([
                'user_id' => $user->id,
                'session_key' => $sessionKey,
                'session_id_encrypted' => Crypt::encryptString($sessionId),
                'device_label' => $label,
                'ip_address' => $ip,
                'user_agent' => $agent,
                'last_activity' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent($user->id, 'login_session_started', $ip, $agent, ['device' => $label]);

            $changedEnvironment = $previous
                && (($previous->ip_address && $ip && $previous->ip_address !== $ip)
                    || ($previous->user_agent && $agent && ! hash_equals(hash('sha256', $previous->user_agent), hash('sha256', $agent))));

            if ($changedEnvironment) {
                $this->recordEvent($user->id, 'new_environment_detected', $ip, $agent, [
                    'device' => $label,
                    'previous_ip' => $previous->ip_address,
                    'previous_device' => $previous->device_label,
                ]);

                try {
                    app(NotificationService::class)->pushToUser(
                        $user,
                        'security_new_environment',
                        'ورود از محیط جدید',
                        'یک نشست جدید با دستگاه یا نشانی شبکه متفاوت برای حساب شما ثبت شد. اگر این ورود متعلق به شما نیست، نشست‌های ناشناس را از مرکز امنیت خارج کنید.',
                        ['url' => route('platform.security')],
                    );
                } catch (\Throwable) {
                    // Keep the security event even when notifications are unavailable.
                }
            }
            // Retention cleanup runs once per newly observed session, not on every page request.
            DB::table('farast_account_sessions')
                ->where('user_id', $user->id)
                ->where('last_activity', '<', now()->subDays(30))
                ->delete();
            DB::table('farast_security_events')
                ->where('user_id', $user->id)
                ->where('created_at', '<', now()->subDays(180))
                ->delete();
        } else {
            DB::table('farast_account_sessions')
                ->where('id', $tracked->id)
                ->update([
                    'last_activity' => now(),
                    'ip_address' => $request->ip(),
                    'updated_at' => now(),
                ]);
        }

        return $next($request);
    }

    private function deviceLabel(string $agent): string
    {
        $browser = preg_match('/Edg\//i', $agent) ? 'Edge'
            : (preg_match('/Firefox\//i', $agent) ? 'Firefox'
            : (preg_match('/Chrome\//i', $agent) ? 'Chrome'
            : (preg_match('/Safari\//i', $agent) ? 'Safari' : 'مرورگر دیگر')));
        $platform = preg_match('/Windows/i', $agent) ? 'Windows'
            : (preg_match('/Android/i', $agent) ? 'Android'
            : (preg_match('/iPhone|iPad|iOS/i', $agent) ? 'iOS'
            : (preg_match('/Mac OS/i', $agent) ? 'macOS'
            : (preg_match('/Linux/i', $agent) ? 'Linux' : 'دستگاه دیگر'))));

        return $platform.' · '.$browser;
    }

    private function recordEvent(int $userId, string $event, ?string $ip, string $agent, array $meta = []): void
    {
        DB::table('farast_security_events')->insert([
            'user_id' => $userId,
            'event' => $event,
            'ip' => $ip,
            'user_agent' => $agent,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
