<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthenticatedSession
{
    private const DEVICE_COOKIE = 'farast_device_key';
    private const DEVICE_COOKIE_MINUTES = 5256000;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        // Login controllers authenticate during route execution. Re-check the
        // user after the controller so device denial is enforced on that very
        // login response, not one page later.
        $response = null;
        $user = $request->user();
        if (! $user) {
            $response = $next($request);
            $user = $request->user();
            if (! $user) {
                return $response;
            }
        }

        $sessionId = $request->session()->getId();
        if ($sessionId === '') {
            return $next($request);
        }

        $deviceKey = (string) $request->cookie(self::DEVICE_COOKIE, '');
        $newDeviceKey = $deviceKey === '';
        if ($newDeviceKey) {
            $deviceKey = Str::random(64);
        }
        $deviceHash = hash('sha256', $deviceKey);

        // A device blocked by its owner cannot continue an existing session or
        // establish a fresh one while its first-party device cookie is present.
        $blocked = DB::table('farast_blocked_devices')
            ->where('user_id', $user->id)
            ->where('device_key_hash', $deviceHash)
            ->whereNull('unblocked_at')
            ->exists();

        if ($blocked) {
            $this->recordEvent($user->id, 'blocked_device_login_prevented', $request->ip(), (string) $request->userAgent(), []);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('warning', 'این دستگاه قبلاً توسط صاحب حساب از ورودهای بعدی منع شده است.');
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
            // A valid remember-cookie may restore authentication after the
            // three-year session cookie has expired. Only a fresh explicit
            // login may establish a new session after this inactivity limit.
            $lastAccountActivity = DB::table('farast_account_sessions')
                ->where('user_id', $user->id)
                ->max('last_activity');
            $explicitLoginAt = (int) $request->session()->pull('farast.explicit_login', 0);

            if ($lastAccountActivity
                && \Illuminate\Support\Carbon::parse($lastAccountActivity)->lt(now()->subYears(3))
                && $explicitLoginAt < now()->subMinutes(5)->timestamp) {
                $expiredCutoff = now()->subYears(3);
                DB::table('farast_account_sessions')
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->where('last_activity', '<', $expiredCutoff)
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
                $this->recordEvent($user->id, 'session_expired_three_years', $request->ip(), (string) $request->userAgent(), ['source' => 'remember_cookie']);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('warning', 'بیش از سه سال از آخرین مراجعه حساب گذشته است؛ برای ادامه، دوباره با اطلاعات ورود وارد شوید.');
            }
        }

        if ($tracked && \Illuminate\Support\Carbon::parse($tracked->last_activity)->lt(now()->subYears(3))) {
            DB::table('farast_account_sessions')->where('id', $tracked->id)->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordEvent($user->id, 'session_expired_three_years', $request->ip(), (string) $request->userAgent(), []);
            try {
                app(NotificationService::class)->pushToUser(
                    $user,
                    'security_session_expired',
                    'نشست قدیمی حساب پایان یافت',
                    'به دلیل بیش از سه سال عدم مراجعه، این نشست بسته شد. برای ادامه دوباره وارد شوید.',
                    ['url' => route('login')],
                );
            } catch (\Throwable) {
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('warning', 'به دلیل بیش از سه سال عدم مراجعه، لازم است دوباره وارد شوید.');
        }

        $agent = substr((string) $request->userAgent(), 0, 4000);
        $ip = $request->ip();
        $label = $this->deviceLabel($agent);

        if (! $tracked) {
            $previous = DB::table('farast_account_sessions')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('last_activity', '>=', now()->subYears(3))
                ->orderByDesc('last_activity')
                ->first();

            DB::table('farast_account_sessions')->insert([
                'user_id' => $user->id,
                'session_key' => $sessionKey,
                'device_key_hash' => $deviceHash,
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
                        'یک نشست جدید با دستگاه یا نشانی شبکه متفاوت برای حساب شما ثبت شد. اگر این ورود متعلق به شما نیست، آن را از مرکز نشست‌ها ببندید.',
                        ['url' => route('platform.security')],
                    );
                } catch (\Throwable) {
                }
            }

            // Keep the audit trail and device controls for the same three-year
            // inactivity window; security events have a separate six-month retention.
            DB::table('farast_account_sessions')
                ->where('user_id', $user->id)
                ->where('last_activity', '<', now()->subYears(3))
                ->delete();
            DB::table('farast_security_events')
                ->where('user_id', $user->id)
                ->where('created_at', '<', now()->subYears(3))
                ->delete();
        } else {
            DB::table('farast_account_sessions')
                ->where('id', $tracked->id)
                ->update([
                    'device_key_hash' => $tracked->device_key_hash ?: $deviceHash,
                    'last_activity' => now(),
                    'ip_address' => $ip,
                    'updated_at' => now(),
                ]);
        }

        $response = $response ?? $next($request);
        if ($newDeviceKey) {
            $response->headers->setCookie(cookie(
                self::DEVICE_COOKIE,
                $deviceKey,
                self::DEVICE_COOKIE_MINUTES,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
        }

        return $response;
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
        ]);
    }
}
