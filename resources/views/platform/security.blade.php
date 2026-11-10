@extends('layouts.app')

@section('content')
<div class="container farast-security-page" dir="rtl">
    <header class="security-heading">
        <div>
            <span class="security-eyebrow">FARAST ACCOUNT PROTECTION</span>
            <h1>مرکز امنیت حساب و مدیریت نشست‌ها</h1>
            <p>تمام دستگاه‌هایی که به حساب شما دسترسی دارند را بررسی و مدیریت کنید.</p>
        </div>
        <span class="security-shield"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
    </header>

    @if(session('status'))
        <div class="security-alert success" role="status">{{ session('status') }}</div>
    @endif
    @if(session('warning'))
        <div class="security-alert error" role="alert">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="security-alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="security-panel">
        <div class="security-panel-heading">
            <div>
                <h2>نشست‌ها و دستگاه‌های متصل</h2>
                <p>نشست‌ها تا خروج کاربر یا سه سال عدم مراجعه معتبر می‌مانند. فعالیت هر دستگاه در این فهرست به‌روزرسانی می‌شود.</p>
            </div>
            <span class="security-count">{{ $sessions->count() }} نشست</span>
        </div>

        @if($sessions->isEmpty())
            <div class="security-empty"><i class="fa-solid fa-laptop" aria-hidden="true"></i><strong>نشست فعالی ثبت نشده است</strong><span>پس از ورود و مراجعه به حساب، دستگاه شما در این فهرست نمایش داده می‌شود.</span></div>
        @else
            <div class="security-session-list">
                @foreach($sessions as $session)
                    <article class="security-session">
                        <span class="security-device-icon"><i class="fa-solid fa-desktop" aria-hidden="true"></i></span>
                        <div class="security-session-info">
                            <div class="security-device-title">
                                {{ $session->device_label ?: 'دستگاه ناشناس' }}
                                @if($session->is_current)<span class="security-current">این دستگاه</span>@endif
                            </div>
                            <div class="security-session-meta">
                                <span>مرورگر: {{ $session->browser_label }}</span>
                                <span>IP: {{ $session->ip_address ?: 'نامشخص' }}</span>
                                <span>آخرین مراجعه: {{ \Illuminate\Support\Carbon::parse($session->last_activity)->format('Y/m/d H:i') }}</span>
                                <span>شروع نشست: {{ \Illuminate\Support\Carbon::parse($session->created_at)->format('Y/m/d H:i') }}</span>
                            </div>
                            <details class="security-device-details">
                                <summary>جزئیات فنی دستگاه</summary>
                                <p dir="ltr">{{ $session->user_agent ?: 'اطلاعات مرورگر ثبت نشده است.' }}</p>
                                <small>شناسه نشست: {{ substr($session->session_key, 0, 12) }}…</small>
                            </details>
                        </div>
                        <div class="security-session-actions">
                            @unless($session->is_current)
                                <form method="post" action="{{ route('platform.security.sessions.revoke', $session->id) }}" class="security-revoke-form">
                                    @csrf
                                    <input type="password" name="current_password" autocomplete="current-password" placeholder="رمز فعلی" aria-label="رمز فعلی برای لغو نشست" required>
                                    <button type="submit" class="security-revoke-button"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> فقط خروج از این نشست</button>
                                </form>
                            @endunless
                            @if(!empty($session->device_key_hash))
                                <form method="post" action="{{ route('platform.security.devices.block', $session->id) }}" class="security-block-device-form" onsubmit="return confirm('این دستگاه از نشست‌های فعال خارج و از ورودهای بعدی با شناسه همین مرورگر منع می‌شود. ادامه می‌دهید؟')">
                                    @csrf
                                    <input type="password" name="current_password" autocomplete="current-password" placeholder="برای مسدودسازی، رمز فعلی" aria-label="رمز فعلی برای مسدودسازی دستگاه" required>
                                    <button type="submit" class="security-block-device-button"><i class="fa-solid fa-ban" aria-hidden="true"></i> مسدودسازی این دستگاه</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
            <form method="post" action="{{ route('platform.security.sessions.revoke-others') }}" class="security-revoke-others">
                @csrf
                <label for="revokeOtherPassword">خروج از تمام نشست‌های دیگر (نشست فعلی باقی می‌ماند)</label>
                <div><input id="revokeOtherPassword" type="password" name="current_password" autocomplete="current-password" placeholder="رمز عبور فعلی" required><button type="submit">خروج از سایر دستگاه‌ها</button></div>
            </form>
        @endif
    </section>

    <section class="security-panel">
        <div class="security-panel-heading">
            <div><h2>دستگاه‌های مسدودشده</h2><p>این دستگاه‌ها با شناسه امن ذخیره‌شده در مرورگر، اجازه ایجاد نشست تازه ندارند. هر زمان خواستید می‌توانید محدودیت را بردارید.</p></div>
            <span class="security-count">{{ $blockedDevices->count() }} دستگاه</span>
        </div>
        @if($blockedDevices->isEmpty())
            <div class="security-empty"><i class="fa-solid fa-shield-check" aria-hidden="true"></i><strong>دستگاهی مسدود نشده است</strong><span>برای جلوگیری از ورود دوباره یک دستگاه مزاحم، از گزینه «مسدودسازی این دستگاه» در فهرست نشست‌ها استفاده کنید.</span></div>
        @else
            <div class="security-session-list">
                @foreach($blockedDevices as $device)
                    <article class="security-session security-blocked-device">
                        <span class="security-device-icon"><i class="fa-solid fa-ban" aria-hidden="true"></i></span>
                        <div class="security-session-info">
                            <div class="security-device-title">{{ $device->device_label ?: 'دستگاه ناشناس' }} <span class="security-current">مسدود</span></div>
                            <div class="security-session-meta">
                                <span>IP هنگام مسدودسازی: {{ $device->ip_address ?: 'نامشخص' }}</span>
                                <span>تاریخ: {{ \Illuminate\Support\Carbon::parse($device->blocked_at)->format('Y/m/d H:i') }}</span>
                            </div>
                        </div>
                        <form method="post" action="{{ route('platform.security.devices.unblock', $device->id) }}" class="security-unblock-form">
                            @csrf
                            <input type="password" name="current_password" autocomplete="current-password" placeholder="رمز عبور فعلی" aria-label="رمز فعلی برای رفع مسدودی" required>
                            <button type="submit">رفع مسدودی دستگاه</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="security-panel">
        <div class="security-panel-heading"><div><h2>تغییر رمز عبور</h2><p>در صورت مشاهده فعالیت ناشناس، رمز عبور را تغییر دهید؛ نشست‌های دیگر بسته خواهند شد.</p></div></div>
        <form method="post" action="{{ route('platform.security.password.update') }}" class="security-password-form">
            @csrf
            <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
            <label>رمز عبور جدید<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
            <label>تکرار رمز عبور جدید<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <button type="submit">تغییر رمز و لغو نشست‌های دیگر</button>
        </form>
        <form method="post" action="{{ route('platform.security.sessions.revoke-all') }}" class="security-revoke-all" onsubmit="return confirm('از همه دستگاه‌ها، از جمله همین دستگاه، خارج می‌شوید. ادامه می‌دهید؟')">
            @csrf
            <label for="revokeAllPassword">خروج از تمام دستگاه‌ها</label>
            <div><input id="revokeAllPassword" type="password" name="current_password" autocomplete="current-password" placeholder="رمز عبور فعلی" required><button type="submit">خروج از همه دستگاه‌ها</button></div>
        </form>
    </section>

    <section class="security-panel security-two-factor-panel">
        <div class="security-panel-heading">
            <div>
                <h2>تأیید دومرحله‌ای (اختیاری)</h2>
                <p>این قابلیت اجباری نیست. فقط اگر خودتان فعالش کنید، پس از رمز عبور به یک کد یک‌بارمصرف نیاز خواهید داشت. فراست هیچ حساب یا سرویس بیرونی برای این کار متصل نمی‌کند.</p>
            </div>
            <span class="security-count">{{ $twoFactorEnabled ? 'فعال' : 'غیرفعال' }}</span>
        </div>

        @if(session('two_factor_recovery_codes'))
            <div class="security-recovery-codes" role="status">
                <strong>کدهای بازیابی شما — فقط همین بار نمایش داده می‌شوند</strong>
                <p>هر کد فقط یک بار قابل استفاده است. همه کدها را اکنون در مکانی امن و خارج از این دستگاه ذخیره کنید. پس از بستن این پیام، امکان مشاهده دوباره آن‌ها وجود ندارد.</p>
                <div class="security-recovery-grid">
                    @foreach(session('two_factor_recovery_codes') as $recoveryCode)
                        <code dir="ltr">{{ $recoveryCode }}</code>
                    @endforeach
                </div>
            </div>
        @endif

        @if($twoFactorEnabled)
            <div class="security-two-factor-state">
                <strong><i class="fa-solid fa-shield-check" aria-hidden="true"></i> تأیید دومرحله‌ای فعال است</strong>
                <span>از تاریخ {{ $twoFactorEnabledAt ? $twoFactorEnabledAt->format('Y/m/d H:i') : 'نامشخص' }}</span>
                <span>{{ $recoveryCodesRemaining }} کد بازیابی استفاده‌نشده باقی مانده است.</span>
            </div>

            <div class="security-two-factor-actions">
                <form method="post" action="{{ route('platform.security.two-factor.recovery-codes') }}" class="security-two-factor-form">
                    @csrf
                    <h3>تولید دوباره کدهای بازیابی</h3>
                    <p>کدهای قبلی باطل می‌شوند. رمز فعلی و کد برنامه احراز هویت یا یک کد بازیابی استفاده‌نشده لازم است.</p>
                    <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label>کد احراز هویت یا بازیابی<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
                    <button type="submit">باطل‌کردن قبلی‌ها و تولید کدهای تازه</button>
                </form>

                <form method="post" action="{{ route('platform.security.two-factor.disable') }}" class="security-two-factor-form security-two-factor-danger" onsubmit="return confirm('با غیرفعال‌سازی، ورود دوباره فقط به رمز عبور نیاز خواهد داشت. ادامه می‌دهید؟')">
                    @csrf
                    <h3>غیرفعال‌سازی تأیید دومرحله‌ای</h3>
                    <p>برای جلوگیری از تغییر غیرمجاز، رمز عبور و کد فعلی یا کد بازیابی لازم است.</p>
                    <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
                    <label>کد احراز هویت یا بازیابی<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
                    <button type="submit">غیرفعال‌سازی</button>
                </form>
            </div>
        @else
            @if($twoFactorPendingSecret)
                <div class="security-two-factor-setup">
                    <h3>۱. ثبت کلید در برنامه احراز هویت</h3>
                    <p>در برنامه TOTP مورداعتماد خود گزینه افزودن حساب را انتخاب کنید و کلید زیر را به‌صورت دستی وارد کنید. نام حساب: {{ auth()->user()->email ?: auth()->user()->mobile }}</p>
                    <div class="security-totp-secret" dir="ltr">{{ $twoFactorPendingSecret }}</div>
                    <p class="security-small-note">این کلید تا ۱۰ دقیقه برای تکمیل راه‌اندازی معتبر است. آن را با کسی به اشتراک نگذارید.</p>
                    <a class="security-otpauth-link" href="{{ $twoFactorProvisioningUri }}">بازکردن لینک ثبت در برنامه احراز هویت</a>

                    <h3>۲. تأیید مالکیت کلید</h3>
                    <p>کد شش‌رقمی فعلی برنامه را وارد کنید. تا پیش از این تأیید، قابلیت فعال نمی‌شود.</p>
                    <form method="post" action="{{ route('platform.security.two-factor.enable') }}" class="security-two-factor-form">
                        @csrf
                        <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
                        <label>کد شش‌رقمی برنامه<input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
                        <button type="submit">تأیید و فعال‌سازی دومرحله‌ای</button>
                    </form>
                </div>
            @else
                <div class="security-two-factor-setup">
                    <h3>فعال‌سازی فقط با انتخاب خودتان</h3>
                    <p>برای شروع، رمز فعلی حساب را وارد کنید. فراست یک کلید محرمانه تولید می‌کند؛ پس از ثبت آن در برنامه TOTP و تأیید کد، قابلیت فعال و کدهای بازیابی تولید می‌شوند.</p>
                    <form method="post" action="{{ route('platform.security.two-factor.setup') }}" class="security-two-factor-form">
                        @csrf
                        <label>رمز عبور فعلی<input type="password" name="current_password" autocomplete="current-password" required></label>
                        <button type="submit">شروع راه‌اندازی اختیاری</button>
                    </form>
                </div>
            @endif
        @endif
    </section>

    <section class="security-panel">
        <div class="security-panel-heading"><div><h2>گزارش فعالیت‌های امنیتی</h2><p>ورودها، خروج‌ها، تغییرات رمز و مدیریت دستگاه‌ها</p></div></div>
        @if($events->isEmpty())
            <div class="security-empty"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><strong>رخداد ثبت‌شده‌ای وجود ندارد</strong></div>
        @else
            <div class="security-events">
                @foreach($events as $event)
                    <div class="security-event">
                        <span class="security-event-icon"><i class="fa-solid fa-shield" aria-hidden="true"></i></span>
                        <div>
                            <strong>{{ [
                                'login_session_started' => 'شروع نشست ورود',
                                'login_failed' => 'تلاش ناموفق برای ورود',
                                'logout' => 'خروج از حساب',
                                'all_sessions_revoked' => 'خروج از تمام دستگاه‌ها',
                                'new_environment_detected' => 'ورود از محیط جدید',
                                'session_revoked' => 'لغو یک نشست',
                                'other_sessions_revoked' => 'لغو نشست‌های دیگر',
                                'password_changed' => 'تغییر رمز عبور',
                                'device_blocked' => 'مسدودسازی دستگاه',
                                'device_unblocked' => 'رفع مسدودی دستگاه',
                                'blocked_device_login_prevented' => 'جلوگیری از ورود دستگاه مسدود',
                                'session_expired_three_years' => 'پایان نشست پس از سه سال عدم مراجعه',
                                'two_factor_enabled' => 'فعال‌سازی تأیید دومرحله‌ای',
                                'two_factor_disabled' => 'غیرفعال‌سازی تأیید دومرحله‌ای',
                                'two_factor_challenge_passed' => 'تأیید دومرحله‌ای موفق',
                                'two_factor_challenge_failed' => 'تلاش ناموفق تأیید دومرحله‌ای',
                                'two_factor_recovery_code_used' => 'استفاده از کد بازیابی دومرحله‌ای',
                                'two_factor_recovery_codes_regenerated' => 'تولید دوباره کدهای بازیابی',
                            ][$event->event] ?? 'رخداد امنیتی حساب' }}</strong>
                            <small>IP: {{ $event->ip ?: 'نامشخص' }} · {{ \Illuminate\Support\Carbon::parse($event->created_at)->format('Y/m/d H:i') }}</small>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <aside class="security-guidance"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p>بستن نشست، دسترسی همان نشست را قطع می‌کند. مسدودسازی دستگاه علاوه بر بستن نشست‌های ثبت‌شده، شناسه امن همان مرورگر را در فهرست منع ورود قرار می‌دهد. حذف کوکی‌ها یا استفاده از مرورگر/دستگاه دیگر می‌تواند این شناسه را تغییر دهد؛ بنابراین این قابلیت جایگزین احراز هویت حساب یا بررسی امنیتی کامل دستگاه نیست.</p></aside>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/security-center.css') }}">
<style>
.farast-security-page .security-recovery-codes{margin:16px 0;padding:16px;border:1px solid #86efac;background:#f0fdf4;border-radius:14px}
.farast-security-page .security-recovery-codes p,.farast-security-page .security-two-factor-setup p,.farast-security-page .security-two-factor-form p{color:#64748b;line-height:1.8;font-size:13px}
.farast-security-page .security-recovery-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(125px,1fr));gap:8px;margin-top:12px}
.farast-security-page .security-recovery-grid code,.farast-security-page .security-totp-secret{display:block;padding:10px;background:#fff;border:1px solid #dbeafe;border-radius:9px;text-align:center;font-weight:700;letter-spacing:1px}
.farast-security-page .security-two-factor-state{display:grid;gap:8px;padding:14px;border-radius:12px;background:#f0fdf4;color:#166534}
.farast-security-page .security-two-factor-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:18px}
.farast-security-page .security-two-factor-form{display:grid;gap:10px;padding:16px;border:1px solid #e2e8f0;border-radius:12px}
.farast-security-page .security-two-factor-form label{display:grid;gap:6px;font-size:13px;font-weight:600}
.farast-security-page .security-two-factor-form input{width:100%;min-width:0;padding:10px;border:1px solid #cbd5e1;border-radius:9px}
.farast-security-page .security-two-factor-form button,.farast-security-page .security-otpauth-link{display:inline-block;border:0;border-radius:9px;padding:10px 14px;background:#1e3a8a;color:white;text-align:center;text-decoration:none;cursor:pointer}
.farast-security-page .security-two-factor-danger{border-color:#fecaca}
.farast-security-page .security-two-factor-danger button{background:#b91c1c}
.farast-security-page .security-two-factor-setup{display:grid;gap:12px;margin-top:12px}
.farast-security-page .security-totp-secret{font-size:18px;overflow-wrap:anywhere}
.farast-security-page .security-small-note{font-size:12px;color:#92400e!important}
.farast-security-page .security-session{align-items:flex-start;flex-wrap:wrap}
.farast-security-page .security-session-info{min-width:240px;flex:1}
.farast-security-page .security-session-meta{display:flex;flex-wrap:wrap;gap:8px 16px}
.farast-security-page .security-session-actions{display:flex;flex-direction:column;gap:10px;min-width:220px}
.farast-security-page .security-revoke-form,.farast-security-page .security-block-device-form,.farast-security-page .security-unblock-form{display:grid;gap:7px}
.farast-security-page .security-session-actions input,.farast-security-page .security-unblock-form input{width:100%;min-width:0}
.farast-security-page .security-block-device-button,.farast-security-page .security-unblock-form button{border:1px solid #d97706;background:#fff7ed;color:#9a3412;border-radius:10px;padding:9px 12px;cursor:pointer}
.farast-security-page .security-device-details{margin-top:10px;font-size:12px;color:#64748b}
.farast-security-page .security-device-details summary{cursor:pointer;font-weight:600}
.farast-security-page .security-device-details p{overflow-wrap:anywhere;background:#f8fafc;padding:8px;border-radius:8px}
.farast-security-page .security-blocked-device{border-color:#fed7aa}
@media(max-width:700px){.farast-security-page .security-session-actions{width:100%}.farast-security-page .security-session-info{width:100%}}
</style>
@endpush
