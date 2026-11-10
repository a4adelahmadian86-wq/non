@extends('layouts.app')

@section('content')
<div class="container farast-security-page" dir="rtl">
    <header class="security-heading">
        <div>
            <span class="security-eyebrow">FARAST ACCOUNT PROTECTION</span>
            <h1>مرکز نشست‌ها و امنیت حساب</h1>
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
