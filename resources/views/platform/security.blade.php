@extends('layouts.app')

@section('content')
<div class="container farast-security-page" dir="rtl">
    <header class="security-heading">
        <div>
            <span class="security-eyebrow">FARAST ACCOUNT PROTECTION</span>
            <h1>مرکز امنیت حساب</h1>
            <p>نشست‌های فعال، دستگاه‌های متصل و رخدادهای امنیتی حساب خود را مدیریت کنید.</p>
        </div>
        <span class="security-shield"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
    </header>

    @if(session('status'))
        <div class="security-alert success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="security-alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="security-panel">
        <div class="security-panel-heading">
            <div><h2>دستگاه‌ها و نشست‌های فعال</h2><p>نشست‌هایی که در {{ $sessionLifetime }} دقیقه اخیر فعالیت داشته‌اند.</p></div>
            <span class="security-count">{{ $sessions->count() }} نشست</span>
        </div>

        @if($sessions->isEmpty())
            <div class="security-empty"><i class="fa-solid fa-laptop" aria-hidden="true"></i><strong>هنوز دستگاه فعالی ثبت نشده است</strong><span>پس از ورود و باز کردن یک صفحه از حساب، دستگاه فعلی در این فهرست ظاهر می‌شود.</span></div>
        @else
            <div class="security-session-list">
                @foreach($sessions as $session)
                    <article class="security-session">
                        <span class="security-device-icon"><i class="fa-solid fa-desktop" aria-hidden="true"></i></span>
                        <div class="security-session-info">
                            <div class="security-device-title">{{ $session->device_label ?: 'دستگاه ناشناس' }} @if($session->is_current)<span class="security-current">این دستگاه</span>@endif</div>
                            <div class="security-session-meta">
                                <span>IP: {{ $session->ip_address ?: 'نامشخص' }}</span>
                                <span>آخرین فعالیت: {{ \Illuminate\Support\Carbon::parse($session->last_activity)->format('Y/m/d H:i') }}</span>
                            </div>
                        </div>
                        @unless($session->is_current)
                            <form method="post" action="{{ route('platform.security.sessions.revoke', $session->id) }}" class="security-revoke-form">
                                @csrf
                                <label><span class="sr-only">رمز عبور فعلی برای خروج این دستگاه</span><input type="password" name="current_password" autocomplete="current-password" placeholder="رمز عبور فعلی" required></label>
                                <button type="submit" class="security-revoke-button"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> خروج دستگاه</button>
                            </form>
                        @endunless
                    </article>
                @endforeach
            </div>
            @if($sessions->where('is_current', false)->isNotEmpty())
                <form method="post" action="{{ route('platform.security.sessions.revoke-others') }}" class="security-revoke-others">
                    @csrf
                    <label for="revokeOtherPassword">برای خروج از سایر دستگاه‌ها، رمز عبور فعلی را وارد کنید</label>
                    <div><input id="revokeOtherPassword" type="password" name="current_password" autocomplete="current-password" required><button type="submit">خروج از همه دستگاه‌های دیگر</button></div>
                </form>
            @endif
        @endif
    </section>

    <section class="security-panel">
        <div class="security-panel-heading"><div><h2>رخدادهای امنیتی اخیر</h2><p>ورودها و تغییرات مهم مرتبط با حساب شما</p></div></div>
        @if($events->isEmpty())
            <div class="security-empty"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><strong>رخداد ثبت‌شده‌ای وجود ندارد</strong></div>
        @else
            <div class="security-events">
                @foreach($events as $event)
                    <div class="security-event">
                        <span class="security-event-icon"><i class="fa-solid fa-shield" aria-hidden="true"></i></span>
                        <div><strong>{{ [
                            'login_session_started' => 'شروع نشست ورود',
                            'new_environment_detected' => 'ورود از محیط جدید',
                            'session_revoked' => 'خروج یک دستگاه',
                            'other_sessions_revoked' => 'خروج از سایر دستگاه‌ها',
                            'password_changed' => 'تغییر رمز عبور',
                        ][$event->event] ?? 'رخداد امنیتی حساب' }}</strong><small>IP: {{ $event->ip ?: 'نامشخص' }} · {{ \Illuminate\Support\Carbon::parse($event->created_at)->format('Y/m/d H:i') }}</small></div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <aside class="security-guidance"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p>اگر دستگاه یا نشانی IP ناشناسی مشاهده کردید، نشست آن را خارج کنید و در صورت مشکوک بودن ورود، رمز عبور خود را نیز تغییر دهید. نشست خارج‌شده در درخواست بعدی آن دستگاه قطع می‌شود.</p></aside>
</div>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/security-center.css') }}">
@endpush
