<?php

namespace App\Http\Controllers;

use App\Models\EmailOtpChallenge;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if ($request->query('continue')) {
            $request->session()->put('url.intended', $request->query('continue'));
        }

        return view('auth.login', ['continueUrl' => $request->query('continue')]);
    }

    public function phoneContinue(Request $request)
    {
        $phone = $this->validatedPhone($request);
        $user = User::where('mobile', $phone)->first();

        if ($user && $user->is_blocked) {
            throw ValidationException::withMessages(['mobile' => 'حساب کاربری شما غیرفعال شده است.']);
        }

        $request->session()->put('login.phone', $phone);

        if ($user && $user->password) {
            return response()->json(['ok' => true, 'next' => route('login.password')]);
        }

        $this->issueSmsOtp($phone, app(SmsService::class));
        $request->session()->put('register.mobile', $phone);

        return response()->json(['ok' => true, 'next' => route('register'), 'mode' => 'register']);
    }

    public function requestOtp(Request $request, SmsService $sms)
    {
        $phone = $this->validatedPhone($request);

        if (User::where('mobile', $phone)->exists()) {
            throw ValidationException::withMessages(['mobile' => 'این شماره دارای حساب است. رمز عبور خود را وارد کنید.']);
        }

        $request->session()->put('register.mobile', $phone);
        $this->issueSmsOtp($phone, $sms);

        return response()->json(['ok' => true, 'message' => 'کد تأیید برای ساخت حساب ارسال شد.']);
    }

    public function verifyRegistrationOtp(Request $request)
    {
        $phone = $request->session()->get('register.mobile');
        abort_unless($phone, 422, 'فرآیند ثبت‌نام پیدا نشد.');
        $this->consumeSmsOtp($phone, (string) $request->input('code'));
        $request->session()->put('register.verified', true);

        return redirect()->route('register');
    }

    public function registerForm(Request $request)
    {
        $phone = $request->session()->get('register.mobile');

        if (! $phone) {
            return redirect()->route('login');
        }

        return view('auth.register', [
            'phone' => $phone,
            'verified' => (bool) $request->session()->get('register.verified', false),
        ]);
    }

    public function registerStore(Request $request, EmailService $emailService)
    {
        $phone = $request->session()->get('register.mobile');
        abort_unless($phone && (bool) $request->session()->get('register.verified', false), 403, 'ابتدا شماره موبایل را تأیید کنید.');

        if (User::where('mobile', $phone)->exists()) {
            return redirect()->route('login')->with('warning', 'این شماره قبلاً دارای حساب است.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
        ], [
            'name.required' => 'نام و نام خانوادگی را وارد کنید.',
            'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.',
            'password.confirmed' => 'تکرار رمز عبور با رمز یکسان نیست.',
            'email.email' => 'ایمیل معتبر نیست.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'mobile' => $phone,
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'role' => 'user',
            'is_verified' => true,
            'is_blocked' => false,
        ]);

        try {
            $emailService->sendWelcome($user);
        } catch (\Throwable) {
            // ثبت‌نام نباید به خاطر ایمیل شکست بخورد
        }

        $request->session()->forget(['login.phone', 'register.mobile', 'register.verified']);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('editor'));
    }

    public function passwordForm(Request $request)
    {
        $phone = $request->session()->get('login.phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        return view('auth.password', ['phone' => $phone]);
    }

    public function passwordLogin(Request $request)
    {
        $phone = $request->session()->get('login.phone');
        abort_unless($phone, 422);
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = User::where('mobile', $phone)->firstOrFail();

        if ($user->is_blocked || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'رمز عبور صحیح نیست.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget(['login.phone']);

        return redirect()->intended($user->isAdmin() ? route('admin.index') : route('editor'));
    }

    public function emailLoginForm(Request $request)
    {
        return view('auth.email-login', ['otpStep' => $request->session()->has('email_login.email')]);
    }

    public function requestEmailOtp(Request $request, EmailService $emailService)
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! $user->email) {
            throw ValidationException::withMessages(['email' => 'این ایمیل در حساب کاربری شما ثبت نشده است.']);
        }

        $code = (string) random_int(100000, 999999);
        EmailOtpChallenge::where('email', $email)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        EmailOtpChallenge::create(['email' => $email, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(3)]);
        $request->session()->put('email_login.email', $email);
        $emailService->sendOtp($email, $code, $user);

        return response()->json(['ok' => true, 'message' => 'کد تأیید به ایمیل شما ارسال شد.']);
    }

    public function verifyEmailOtp(Request $request)
    {
        $email = $request->session()->get('email_login.email');
        abort_unless($email, 422, 'درخواست ایمیل پیدا نشد.');
        $otp = EmailOtpChallenge::where('email', $email)->whereNull('consumed_at')->where('expires_at', '>', now())->latest()->first();
        abort_unless($otp, 422, 'کد منقضی شده است.');

        if ($otp->attempts >= 5) {
            abort(429, 'تعداد تلاش‌ها بیش از حد مجاز است.');
        }

        $otp->increment('attempts');
        abort_unless(Hash::check(trim((string) $request->input('code')), $otp->code_hash), 422, 'کد نادرست است.');
        $otp->update(['consumed_at' => now()]);
        $user = User::whereRaw('LOWER(email) = ?', [$email])->firstOrFail();
        $request->session()->put('email_login.user_id', $user->id);

        return redirect()->route('login.email.mobile');
    }

    public function emailMobileForm(Request $request)
    {
        $user = User::find($request->session()->get('email_login.user_id'));

        if (! $user) {
            return redirect()->route('login.email');
        }

        return view('auth.email-mobile', ['mobile' => $user->mobile, 'otpSent' => $request->session()->has('email_login.mobile')]);
    }

    public function requestEmailMobileOtp(Request $request, SmsService $sms)
    {
        $user = User::findOrFail($request->session()->get('email_login.user_id'));
        $phone = $this->normalizePhone((string) $request->input('mobile'));
        abort_unless(hash_equals($user->mobile, $phone), 422, 'شماره موبایل با حساب این ایمیل مطابقت ندارد.');
        $request->session()->put('email_login.mobile', $phone);
        $this->issueSmsOtp($phone, $sms);

        return response()->json(['ok' => true, 'message' => 'کد تأیید به شماره موبایل شما ارسال شد.']);
    }

    public function verifyEmailMobileOtp(Request $request)
    {
        $user = User::findOrFail($request->session()->get('email_login.user_id'));
        $phone = $request->session()->get('email_login.mobile');
        abort_unless($phone && hash_equals($user->mobile, $phone), 422);
        $this->consumeSmsOtp($phone, (string) $request->input('code'));

        if ($user->is_blocked) {
            abort(403);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget(['email_login.email', 'email_login.user_id', 'email_login.mobile']);

        return redirect()->intended($user->isAdmin() ? route('admin.index') : route('editor'));
    }

    public function forgotPasswordForm(Request $request)
    {
        return view('auth.forgot-password', ['step' => $request->query('step', 'phone')]);
    }

    public function sendPasswordResetOtp(Request $request, SmsService $sms)
    {
        $phone = $this->validatedPhone($request);

        if (! User::where('mobile', $phone)->exists()) {
            throw ValidationException::withMessages(['mobile' => 'حسابی با این شماره پیدا نشد.']);
        }

        $request->session()->put('password_reset.mobile', $phone);
        $this->issueSmsOtp($phone, $sms);

        return response()->json(['ok' => true, 'message' => 'کد بازیابی برای شما پیامک شد.']);
    }

    public function verifyPasswordResetOtp(Request $request)
    {
        $phone = $request->session()->get('password_reset.mobile');
        abort_unless($phone, 422);
        $this->consumeSmsOtp($phone, (string) $request->input('code'));
        $request->session()->put('password_reset.verified', true);

        return redirect()->route('password.reset');
    }

    public function resetPasswordForm(Request $request)
    {
        abort_unless((bool) $request->session()->get('password_reset.verified', false), 403);

        return view('auth.reset-password');
    }

    public function resetPassword(Request $request, EmailService $emailService)
    {
        $phone = $request->session()->get('password_reset.mobile');
        abort_unless($phone && (bool) $request->session()->get('password_reset.verified', false), 403);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user = User::where('mobile', $phone)->firstOrFail();
        $user->update(['password' => $data['password']]);

        try {
            $emailService->sendPasswordChanged($user);
        } catch (\Throwable) {
        }

        $request->session()->forget(['password_reset.mobile', 'password_reset.verified']);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended($user->isAdmin() ? route('admin.index') : route('editor'))->with('status', 'رمز عبور با موفقیت تغییر کرد.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function validatedPhone(Request $request): string
    {
        $phone = $this->normalizePhone((string) $request->input('mobile'));
        Validator::make(['mobile' => $phone], ['mobile' => ['required', 'regex:/^09\d{9}$/']], ['mobile.regex' => 'شماره موبایل معتبر نیست.'])->validate();

        return $phone;
    }

    private function normalizePhone(string $phone): string
    {
        return strtr(trim($phone), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٬' => '', ' ' => '', '-' => '', '‌' => '',
        ]);
    }

    private function issueSmsOtp(string $phone, SmsService $sms): void
    {
        $code = (string) random_int(100000, 999999);
        OtpChallenge::where('mobile', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpChallenge::create(['mobile' => $phone, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(3)]);
        $sms->sendOtp($phone, $code);
    }

    private function consumeSmsOtp(string $phone, string $code): void
    {
        $otp = OtpChallenge::where('mobile', $phone)->whereNull('consumed_at')->where('expires_at', '>', now())->latest()->first();
        abort_unless($otp, 422, 'کد منقضی شده است.');

        if ($otp->attempts >= 5) {
            abort(429, 'تعداد تلاش‌ها بیش از حد مجاز است.');
        }

        $otp->increment('attempts');
        abort_unless(Hash::check($code, $otp->code_hash), 422, 'کد نادرست است.');
        $otp->update(['consumed_at' => now()]);
    }
}
