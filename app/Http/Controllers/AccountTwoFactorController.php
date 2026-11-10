<?php

namespace App\\Http\\Controllers;

use App\\Models\\User;
use App\\Services\\LocalTotpService;
use App\\Services\\NotificationService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Crypt;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\RateLimiter;
use Illuminate\\Validation\\ValidationException;
use Throwable;

class AccountTwoFactorController extends Controller
{
    public function challenge(Request $request)
    {
        abort_unless($request->user()?->two_factor_enabled_at, 404);
        if ($request->session()->boolean('auth.two_factor_passed')) {
            return redirect()->intended(route('editor'));
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request, LocalTotpService $totp)
    {
        $user = $request->user();
        abort_unless($user?->two_factor_enabled_at, 404);

        $key = 'farast:2fa:challenge:'.$user->id.':'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'تعداد تلاش‌ها بیش از حد مجاز است. پس از '.RateLimiter::availableIn($key).' ثانیه دوباره تلاش کنید.',
            ]);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);
        if (! $this->verifyFactor($user, $data['code'], $totp)) {
            RateLimiter::hit($key, 600);
            $this->recordEvent($request, 'two_factor_challenge_failed');
            throw ValidationException::withMessages(['code' => 'کد تأیید معتبر نیست یا قبلاً استفاده شده است.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('auth.two_factor_passed', true);
        $this->recordEvent($request, 'two_factor_challenge_passed');

        return redirect()->intended(route('editor'))->with('status', 'تأیید دومرحله‌ای با موفقیت انجام شد.');
    }

    public function startEnrollment(Request $request, LocalTotpService $totp)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();
        abort_if($user->two_factor_enabled_at, 409, 'تأیید دومرحله‌ای قبلاً فعال شده است.');

        $secret = $totp->generateSecret();
        $request->session()->put('farast.two_factor.pending_secret', $secret);
        $request->session()->put('farast.two_factor.pending_at', now()->timestamp);

        return back()->with('status', 'کلید راه‌اندازی آماده شد. آن را در برنامه احراز هویت خود ثبت و کد شش‌رقمی را وارد کنید.');
    }

    public function enable(Request $request, LocalTotpService $totp)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'digits:6'],
        ]);

        $secret = (string) $request->session()->get('farast.two_factor.pending_secret', '');
        $startedAt = (int) $request->session()->get('farast.two_factor.pending_at', 0);
        abort_unless($secret !== '' && $startedAt >= now()->subMinutes(10)->timestamp, 422, 'مهلت راه‌اندازی تمام شده است؛ دوباره شروع کنید.');
        abort_if($request->user()->two_factor_enabled_at, 409);

        if (! $totp->verify($secret, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'کد برنامه احراز هویت صحیح نیست. ساعت دستگاه را بررسی و دوباره تلاش کنید.']);
        }

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_enabled_at' => now(),
        ])->save();

        $codes = $this->replaceRecoveryCodes($user, $totp);
        $request->session()->forget(['farast.two_factor.pending_secret', 'farast.two_factor.pending_at']);
        $request->session()->put('auth.two_factor_passed', true);

        $this->recordEvent($request, 'two_factor_enabled');
        $this->notify($user, 'security_two_factor_enabled', 'تأیید دومرحله‌ای فعال شد', 'تأیید دومرحله‌ای برای حساب شما فعال شد. کدهای بازیابی را در مکانی امن نگه دارید.', ['url' => route('platform.security')]);

        return back()
            ->with('status', 'تأیید دومرحله‌ای فعال شد. کدهای بازیابی فقط همین یک بار نمایش داده می‌شوند.')
            ->with('two_factor_recovery_codes', $codes);
    }

    public function regenerateRecoveryCodes(Request $request, LocalTotpService $totp)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:80'],
        ]);
        $user = $request->user();
        abort_unless($user->two_factor_enabled_at && $user->two_factor_secret, 409);

        if (! $this->verifyFactor($user, (string) $request->input('code'), $totp)) {
            throw ValidationException::withMessages(['code' => 'کد تأیید معتبر نیست.']);
        }

        $codes = $this->replaceRecoveryCodes($user, $totp);
        $this->recordEvent($request, 'two_factor_recovery_codes_regenerated');
        $this->notify($user, 'security_two_factor_recovery_regenerated', 'کدهای بازیابی تازه شدند', 'کدهای بازیابی قبلی دیگر معتبر نیستند. کدهای تازه را در مکانی امن ذخیره کنید.', ['url' => route('platform.security')]);

        return back()
            ->with('status', 'کدهای بازیابی قبلی باطل و کدهای تازه تولید شدند.')
            ->with('two_factor_recovery_codes', $codes);
    }

    public function disable(Request $request, LocalTotpService $totp)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => ['required', 'string', 'max:80'],
        ]);
        $user = $request->user();
        abort_unless($user->two_factor_enabled_at && $user->two_factor_secret, 409);

        if (! $this->verifyFactor($user, (string) $request->input('code'), $totp)) {
            throw ValidationException::withMessages(['code' => 'کد تأیید معتبر نیست.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled_at' => null,
        ])->save();

        DB::table('farast_two_factor_recovery_codes')->where('user_id', $user->id)->delete();
        $request->session()->put('auth.two_factor_passed', true);
        $request->session()->forget(['farast.two_factor.pending_secret', 'farast.two_factor.pending_at']);

        $this->recordEvent($request, 'two_factor_disabled');
        $this->notify($user, 'security_two_factor_disabled', 'تأیید دومرحله‌ای غیرفعال شد', 'تأیید دومرحله‌ای حساب شما غیرفعال شد. اگر این تغییر توسط شما انجام نشده، فوراً رمز عبور را تغییر دهید.', ['url' => route('platform.security')]);

        return back()->with('status', 'تأیید دومرحله‌ای غیرفعال شد.');
    }

    private function verifyFactor(User $user, string $code, LocalTotpService $totp): bool
    {
        try {
            $secret = Crypt::decryptString((string) $user->two_factor_secret);
            if ($totp->verify($secret, $code)) {
                return true;
            }
        } catch (Throwable) {
            // Fall through to a one-time recovery-code check.
        }

        $normalized = $totp->normalizeRecoveryCode($code);
        if (strlen($normalized) < 12 || strlen($normalized) > 24) {
            return false;
        }

        $hash = hash('sha256', $normalized);
        $recovery = DB::table('farast_two_factor_recovery_codes')
            ->where('user_id', $user->id)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->first();

        if (! $recovery) {
            return false;
        }

        return DB::table('farast_two_factor_recovery_codes')
            ->where('id', $recovery->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function replaceRecoveryCodes(User $user, LocalTotpService $totp): array
    {
        $codes = $totp->generateRecoveryCodes(10);
        DB::table('farast_two_factor_recovery_codes')->where('user_id', $user->id)->delete();

        $now = now();
        DB::table('farast_two_factor_recovery_codes')->insert(array_map(
            fn (string $code) => [
                'user_id' => $user->id,
                'code_hash' => hash('sha256', $totp->normalizeRecoveryCode($code)),
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $codes,
        ));

        return $codes;
    }

    private function recordEvent(Request $request, string $event): void
    {
        DB::table('farast_security_events')->insert([
            'user_id' => $request->user()->id,
            'event' => $event,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 4000),
            'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function notify(User $user, string $type, string $title, string $body, array $data = []): void
    {
        try {
            app(NotificationService::class)->pushToUser($user, $type, $title, $body, $data);
        } catch (Throwable) {
            // The account security action does not depend on notification delivery.
        }
    }
}
