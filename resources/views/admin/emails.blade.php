@extends('layouts.app')

@section('content')
<div class="master-admin" dir="rtl">
  <aside class="admin-sidebar">
    <div class="admin-brand">
      <span class="admin-logo"><i class="fa-solid fa-layer-group"></i></span>
      <div><b>فراست</b><small>مدیریت مرکزی</small></div>
    </div>
    <nav>
      <a href="{{ route('admin.index') }}"><i class="fa-solid fa-gauge-high"></i><span>نمای کلی</span></a>
      <a href="{{ route('admin.finance') }}"><i class="fa-solid fa-wallet"></i><span>مالی</span></a>
      <a class="active" href="{{ route('admin.emails') }}"><i class="fa-solid fa-envelope"></i><span>سیستم ایمیل</span></a>
      <a href="{{ route('admin.social') }}"><i class="fa-solid fa-share-nodes"></i><span>شبکه‌های اجتماعی</span></a>
    </nav>
    <div class="admin-side-footer"><span class="live-dot"></span> سامانه عملیاتی</div>
  </aside>

  <main class="admin-main">
    <header class="admin-header">
      <div>
        <span class="admin-kicker">EMAIL SYSTEM</span>
        <h1>سیستم ایمیل حرفه‌ای</h1>
        <p>سرویس‌های رایگان رایج، کنترل انواع ایمیل، تست ارسال و لاگ از یک پنل.</p>
      </div>
      <div class="admin-profile">
        <div class="profile-avatar">م</div>
        <div><b>مدیر اصلی</b><small>{{ auth()->user()->mobile }}</small></div>
        <form method="post" action="{{ route('logout') }}">@csrf
          <button title="خروج"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
        </form>
      </div>
    </header>

    @if(session('status'))
      <div class="admin-toast"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif

    @if($errors->any())
      <div class="admin-toast" style="background:#3b1d1d;border-color:#7f1d1d;color:#fecaca">
        <i class="fa-solid fa-circle-exclamation"></i>{{ $errors->first() }}
      </div>
    @endif

    {{-- سرویس‌دهنده --}}
    <section class="admin-section">
      <div class="section-heading">
        <div>
          <span class="section-icon"><i class="fa-solid fa-cloud"></i></span>
          <div>
            <h2>سرویس‌دهنده ایمیل (رایگان)</h2>
            <p>Resend، Brevo و Mailtrap برای شروع رایگان و پایدار پیشنهاد می‌شوند.</p>
          </div>
        </div>
        <span class="configured-pill {{ $providerConfigured ? '' : 'offline' }}">
          {{ $providers[$mailer] ?? $mailer }} · {{ $providerConfigured ? 'آماده' : 'نیاز به تنظیم' }}
        </span>
      </div>

      <form method="post" action="{{ route('admin.emails.settings') }}" class="settings-grid">
        @csrf

        <div class="field">
          <label>سرویس‌دهنده</label>
          <select name="mail_provider">
            @foreach($providers as $key => $label)
              <option value="{{ $key }}" @selected($mailer === $key)>{{ $label }}</option>
            @endforeach
          </select>
          <small>
            <b>Resend</b>: بهترین برای پروداکشن رایگان ·
            <b>Brevo</b>: SMTP رایگان روزانه ·
            <b>Mailtrap</b>: فقط تست ·
            <b>Log</b>: فقط در لاگ سرور
          </small>
        </div>

        <div class="field">
          <label>آدرس فرستنده (From)</label>
          <input type="email" name="mail_from_address" value="{{ $mailFrom }}" placeholder="noreply@yourdomain.com">
        </div>

        <div class="field">
          <label>نام فرستنده</label>
          <input name="mail_from_name" value="{{ $mailFromName }}" placeholder="FARAST">
        </div>

        <div class="field secret-field">
          <label>کلید Resend API</label>
          <input type="password" name="resend_api_key" placeholder="{{ $hasResendKey ? 'تنظیم شده — برای تغییر وارد کنید' : 're_...' }}" autocomplete="new-password">
          <small>از <a href="https://resend.com" target="_blank" rel="noopener">resend.com</a> رایگان بگیرید.</small>
        </div>

        <div class="field secret-field">
          <label>کلید / رمز Brevo</label>
          <input type="password" name="brevo_api_key" placeholder="{{ $hasBrevoKey ? 'تنظیم شده — برای تغییر وارد کنید' : 'کلید SMTP Brevo' }}" autocomplete="new-password">
          <small>از پنل Brevo بخش SMTP & API</small>
        </div>

        <div class="field">
          <label>SMTP Host</label>
          <input name="mail_host" value="{{ $mailHost }}" placeholder="smtp-relay.brevo.com">
        </div>

        <div class="field">
          <label>SMTP Port</label>
          <input type="number" name="mail_port" value="{{ $mailPort }}" min="1">
        </div>

        <div class="field">
          <label>SMTP Username</label>
          <input name="mail_username" value="{{ $mailUsername }}" autocomplete="off">
        </div>

        <div class="field secret-field">
          <label>SMTP Password</label>
          <input type="password" name="mail_password" placeholder="{{ $hasSmtpPassword ? 'تنظیم شده — برای تغییر وارد کنید' : 'رمز SMTP' }}" autocomplete="new-password">
        </div>

        <div class="field">
          <label>Encryption</label>
          <select name="mail_encryption">
            <option value="tls" @selected(($mailEncryption ?: 'tls') === 'tls')>TLS</option>
            <option value="ssl" @selected($mailEncryption === 'ssl')>SSL</option>
            <option value="null" @selected($mailEncryption === '' || $mailEncryption === null)>بدون</option>
          </select>
        </div>

        <label class="toggle-card">
          <span><i class="fa-solid fa-power-off"></i> فعال بودن کل سیستم ایمیل</span>
          <input type="hidden" name="email_enabled" value="0">
          <input type="checkbox" name="email_enabled" value="1" {{ $flags['email_enabled'] ? 'checked' : '' }}>
          <b></b>
        </label>

        @foreach($types as $key => $label)
          <label class="toggle-card">
            <span><i class="fa-solid fa-envelope"></i> {{ $label }}</span>
            <input type="hidden" name="email_{{ $key }}_enabled" value="0">
            <input type="checkbox" name="email_{{ $key }}_enabled" value="1" {{ ($flags['email_'.$key.'_enabled'] ?? true) ? 'checked' : '' }}>
            <b></b>
          </label>
        @endforeach

        <label class="toggle-card">
          <span><i class="fa-solid fa-bolt"></i> ارسال همزمان (بدون Queue)</span>
          <input type="hidden" name="email_sync" value="0">
          <input type="checkbox" name="email_sync" value="1" {{ filter_var(\App\Models\SiteSetting::read('email_sync', false), FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
          <b></b>
        </label>

        <div class="settings-actions">
          <button class="admin-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره تنظیمات ایمیل</button>
        </div>
      </form>
    </section>

    <section class="admin-section">
      <div class="section-heading">
        <div>
          <span class="section-icon green-bg"><i class="fa-solid fa-paper-plane"></i></span>
          <div>
            <h2>ارسال ایمیل آزمایشی</h2>
            <p>بعد از ذخیره سرویس‌دهنده، اینجا تست کنید.</p>
          </div>
        </div>
      </div>

      <form method="post" action="{{ route('admin.emails.test') }}" class="settings-grid" style="grid-template-columns:1fr auto">
        @csrf
        <div class="field">
          <label>آدرس ایمیل مقصد</label>
          <input type="email" name="test_email" required placeholder="you@example.com" value="{{ old('test_email') }}">
        </div>
        <div class="settings-actions" style="align-self:end">
          <button class="admin-secondary"><i class="fa-solid fa-flask"></i> ارسال تست</button>
        </div>
      </form>
    </section>

    <section class="admin-section">
      <div class="section-heading">
        <div>
          <span class="section-icon"><i class="fa-solid fa-headset"></i></span>
          <div>
            <h2>پاسخ سریع به تیکت‌ها</h2>
            <p>پاسخ شما برای کاربر ایمیل می‌شود.</p>
          </div>
        </div>
        <span class="count-pill">{{ $tickets->count() }} تیکت اخیر</span>
      </div>

      <div class="user-table">
        @forelse($tickets as $ticket)
          <article class="user-row" style="flex-direction:column;align-items:stretch;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div>
                <b>#{{ $ticket->id }} — {{ $ticket->subject }}</b>
                <small style="display:block;opacity:.75">{{ $ticket->user?->name }} · {{ $ticket->user?->email ?: 'بدون ایمیل' }} · {{ $ticket->status }}</small>
              </div>
              <span class="badge">{{ $ticket->created_at?->format('Y/m/d H:i') }}</span>
            </div>
            <form method="post" action="{{ route('admin.tickets.reply', $ticket) }}" style="display:grid;gap:10px">
              @csrf
              <textarea name="body" rows="3" required maxlength="5000" placeholder="متن پاسخ مدیر..." style="width:100%;background:#121a2f;border:1px solid #2a3d66;border-radius:12px;color:#e8eefc;padding:12px"></textarea>
              <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <select name="status" style="background:#121a2f;border:1px solid #2a3d66;border-radius:10px;color:#e8eefc;padding:8px 12px">
                  <option value="answered">پاسخ‌داده‌شده</option>
                  <option value="open">باز</option>
                  <option value="closed">بسته</option>
                </select>
                <button class="admin-primary" type="submit"><i class="fa-solid fa-reply"></i> ارسال پاسخ + ایمیل</button>
              </div>
            </form>
          </article>
        @empty
          <div class="empty-admin">تیکتی ثبت نشده است.</div>
        @endforelse
      </div>
    </section>

    <section class="admin-section">
      <div class="section-heading">
        <div>
          <span class="section-icon orange-bg"><i class="fa-solid fa-clock-rotate-left"></i></span>
          <div>
            <h2>لاگ ایمیل‌ها</h2>
            <p>آخرین ارسال‌ها، وضعیت و خطاها.</p>
          </div>
        </div>
        <span class="count-pill">{{ $logs->count() }} مورد</span>
      </div>

      <div class="user-table">
        @forelse($logs as $log)
          <article class="user-row">
            <div class="user-main">
              <span class="user-avatar"><i class="fa-solid fa-envelope"></i></span>
              <div>
                <b>{{ $log->subject }}</b>
                <small>{{ $log->to_email }} · {{ $types[$log->type] ?? $log->type }} · {{ $log->meta['provider'] ?? '-' }}</small>
              </div>
            </div>
            <div class="user-badges">
              <span class="badge {{ $log->status === 'sent' ? 'ok' : ($log->status === 'failed' ? 'danger' : '') }}">{{ $log->status }}</span>
              <span class="badge">{{ $log->created_at?->format('Y/m/d H:i') }}</span>
            </div>
            @if($log->error)
              <div style="width:100%;margin-top:8px;font-size:12px;color:#fca5a5">{{ \Illuminate\Support\Str::limit($log->error, 180) }}</div>
            @endif
          </article>
        @empty
          <div class="empty-admin">هنوز لاگی ثبت نشده است.</div>
        @endforelse
      </div>
    </section>
  </main>
</div>
@endsection
