@extends('layouts.admin')
@section('admin-content')
@if(session('status'))
            <div class="farast-admin-toast"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif

        <section id="overview" class="admin-command-hero">
            <div class="hero-copy">
                <div class="hero-badge"><i class="fa-solid fa-shield-halved"></i> محیط مدیریت امن</div>
                <h1>مرکز عملیات فراست</h1>
                <p>نمای یکپارچه کاربران، اسناد، پروژه‌ها، سرویس‌ها و رخدادهای ثبت‌شده؛ دسترسی هر بخش بر اساس نقش و دامنهٔ شما اعمال می‌شود.</p>
                <div class="hero-actions">
                    @if($console->sectionAllowed(auth()->user(), 'store'))<a class="admin-primary" href="{{ route('admin.store.create') }}"><i class="fa-solid fa-plus"></i> افزودن محصول</a><a class="admin-outline" href="{{ route('admin.store.index') }}"><i class="fa-solid fa-boxes-stacked"></i> مرکز محصولات</a>@endif
                    @if($console->sectionAllowed(auth()->user(), 'users'))<a class="admin-outline" href="{{ route('admin.crm.projects.index') }}"><i class="fa-solid fa-diagram-project"></i> پروژه‌ها و پرونده‌های مشتریان</a><a class="admin-outline" href="#users"><i class="fa-solid fa-users"></i> مدیریت کاربران</a>@endif
                    @if($console->sectionAllowed(auth()->user(), 'settings'))<a class="admin-outline" href="#ai-settings"><i class="fa-solid fa-sparkles"></i> تنظیم AI</a>@endif
                </div>
            </div>
            <div class="hero-orbit" aria-hidden="true"><div class="orbit-core"><span></span><span></span><span></span><span></span><b></b></div><i class="orbit-dot d1"></i><i class="orbit-dot d2"></i><i class="orbit-dot d3"></i></div>
        </section>

        <section class="admin-metric-grid" aria-label="شاخص‌های اصلی">
            <article class="metric-card metric-blue"><div class="metric-top"><span>کاربران</span><i class="fa-solid fa-users"></i></div><strong>{{ number_format($stats['users']) }}</strong><small>{{ number_format($stats['new_users_week']) }} ثبت‌نام در ۷ روز · {{ number_format($stats['blocked_users']) }} مسدود</small></article>
            <article class="metric-card metric-violet"><div class="metric-top"><span>اسناد</span><i class="fa-solid fa-file-lines"></i></div><strong>{{ number_format($stats['documents']) }}</strong><small>{{ number_format($stats['new_documents_week']) }} سند جدید در ۷ روز اخیر</small></article>
            <article class="metric-card metric-cyan"><div class="metric-top"><span>تعامل AI</span><i class="fa-solid fa-sparkles"></i></div><strong>{{ number_format($stats['ai']) }}</strong><small>{{ number_format($stats['ai_today']) }} تعامل در امروز</small></article>
            @if($scope === 'global' && $console->sectionAllowed(auth()->user(), 'ai'))
            <article class="metric-card metric-green"><div class="metric-top"><span>اعتبارنامه Gemini</span><i class="fa-solid fa-circle-nodes"></i></div><strong class="metric-status">{{ $secretStatus['gemini'] ? 'ثبت شده' : 'ثبت نشده' }}</strong><small>{{ $settings['gemini_model'] }}</small></article>
            @endif
        </section>

        <section class="admin-metric-grid admin-action-metrics" aria-label="اقدام‌های عملیاتی">
            @if($console->sectionAllowed(auth()->user(), 'support_admin'))
                <a class="metric-card metric-orange admin-action-metric" href="{{ route('admin.support.management') }}">
                    <div class="metric-top"><span>تیکت‌های نیازمند پیگیری</span><i class="fa-solid fa-headset"></i></div>
                    <strong>{{ number_format($stats['tickets_open']) }}</strong>
                    <small>گفتگوهای باز یا پاسخ‌داده‌شده · مشاهده صف پشتیبانی</small>
                </a>
            @endif
            @if($console->sectionAllowed(auth()->user(), 'finance'))
                <a class="metric-card metric-green admin-action-metric" href="{{ route('admin.finance') }}">
                    <div class="metric-top"><span>درآمد ماه جاری</span><i class="fa-solid fa-chart-line"></i></div>
                    <strong>{{ number_format($stats['revenue_month']) }}</strong>
                    <small>ریال · کل درآمد ثبت‌شده: {{ number_format($stats['revenue_total']) }} ریال</small>
                </a>
                <a class="metric-card metric-violet admin-action-metric" href="{{ route('admin.finance') }}">
                    <div class="metric-top"><span>موجودی تجمیعی کیف پول</span><i class="fa-solid fa-wallet"></i></div>
                    <strong>{{ number_format($stats['wallet_balance']) }}</strong>
                    <small>ریال · مشاهده دفتر مالی و تراکنش‌ها</small>
                </a>
            @endif
        </section>

        <section id="operations" class="admin-section-grid">
            <article class="admin-panel operations-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">OPERATIONAL SNAPSHOT</span><h2>وضعیت عملیات</h2><p>شاخص‌هایی که برای تصمیم‌های روزمره مدیر مهم هستند.</p></div><span class="status-chip">به‌روزرسانی در {{ now()->format('H:i') }}</span></div>
                <div class="ops-grid">
                    <div><span class="ops-icon"><i class="fa-solid fa-user-check"></i></span><div><b>{{ number_format($stats['active_users']) }}</b><small>کاربر فعال در دامنه</small></div></div>
                    <div><span class="ops-icon purple"><i class="fa-solid fa-file-circle-check"></i></span><div><b>{{ number_format($stats['documents']) }}</b><small>سند موجود</small></div></div>
                    <div><span class="ops-icon cyan"><i class="fa-solid fa-bolt"></i></span><div><b>{{ number_format($stats['ai_today']) }}</b><small>درخواست AI امروز</small></div></div>
                    @if($console->sectionAllowed(auth()->user(), 'users'))<div><span class="ops-icon purple"><i class="fa-solid fa-diagram-project"></i></span><div><b>{{ number_format($stats['projects']) }}</b><small>{{ number_format($stats['active_projects']) }} پروژه فعال مشتریان</small></div></div>@endif
                    @if($console->sectionAllowed(auth()->user(), 'announcements'))<div><span class="ops-icon orange"><i class="fa-solid fa-bullhorn"></i></span><div><b>{{ number_format($announcements->where('is_active', true)->count()) }}</b><small>اعلان فعال</small></div></div>@endif
                </div>
            </article>
            @if($scope === 'global' && $console->sectionAllowed(auth()->user(), 'ai'))
            <article id="ai" class="admin-panel ai-status-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">AI GATEWAY</span><h2>دروازه هوش مصنوعی</h2><p>وضعیت تنظیمات حساس بدون نمایش مقدار کلیدها.</p></div><span class="status-chip {{ $secretStatus['gemini'] ? 'ok' : 'warn' }}"><i class="fa-solid fa-circle"></i>{{ $secretStatus['gemini'] ? 'کلید تنظیم شده' : 'نیازمند تنظیم' }}</span></div>
                <div class="ai-provider"><span class="provider-mark"><i class="fa-solid fa-sparkles"></i></span><div><b>Gemini</b><small>{{ $settings['gemini_model'] }}</small></div><span class="provider-state {{ $secretStatus['gemini'] ? 'ok' : 'warn' }}">{{ $secretStatus['gemini'] ? 'کلید تنظیم شده' : 'بدون کلید' }}</span></div>
                <div class="ai-provider"><span class="provider-mark files"><i class="fa-solid fa-file-shield"></i></span><div><b>Files</b><small>پردازش ورودی‌های بزرگ</small></div><span class="provider-state {{ $secretStatus['files'] ? 'ok' : 'warn' }}">{{ $secretStatus['files'] ? 'کلید تنظیم شده' : 'بدون کلید' }}</span></div>
                <a class="panel-link" href="#ai-settings">باز کردن تنظیمات AI <i class="fa-solid fa-arrow-left"></i></a>
            </article>
@endif
        </section>

@if($console->sectionAllowed(auth()->user(), 'audit'))
        <section id="recent-activity" class="admin-panel admin-panel-wide">
            <div class="admin-panel-head">
                <div><span class="panel-eyebrow">AUDIT TRAIL</span><h2>فعالیت‌های اخیر</h2><p>آخرین رخدادهای ثبت‌شده در دفتر ممیزی، متناسب با دامنهٔ دسترسی شما.</p></div>
                <a class="panel-link" href="{{ route('admin.audit') }}">مشاهده دفتر ممیزی <i class="fa-solid fa-arrow-left"></i></a>
            </div>
            <div class="admin-activity-list">
                @forelse($recentAudit as $event)
                    <article class="admin-activity-row">
                        <span class="admin-activity-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
                        <div><strong>{{ \Illuminate\Support\Str::headline(str_replace(['.', '_'], ' ', $event->action)) }}</strong><small>{{ $event->actor_name ?: 'سامانه' }} · {{ \Illuminate\Support\Carbon::parse($event->created_at)->format('Y/m/d H:i') }}</small></div>
                    </article>
                @empty
                    <div class="admin-empty">هنوز رخداد قابل‌نمایشی در این دامنه ثبت نشده است.</div>
                @endforelse
            </div>
        </section>
@endif

@if($console->sectionAllowed(auth()->user(), 'settings'))
        <section id="ai-settings" class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">CONFIGURATION</span><h2>تنظیمات مرکزی AI و ظرفیت سرویس</h2><p>کلیدها رمزنگاری‌شده ذخیره می‌شوند و مقدار واقعی آن‌ها در رابط نمایش داده نمی‌شود.</p></div></div>
            <form method="post" action="{{ route('admin.settings.update') }}" class="settings-modern">
                @csrf
                <label class="modern-field secret"><span><i class="fa-solid fa-key"></i> کلید Gemini</span><input type="password" name="gemini_api_key" placeholder="{{ $secretStatus['gemini'] ? 'کلید تنظیم شده است؛ برای تغییر وارد کنید' : 'کلید Gemini API' }}" autocomplete="new-password"><small>خالی بگذارید تا مقدار فعلی حفظ شود.</small></label>
                <label class="modern-field secret"><span><i class="fa-solid fa-file-shield"></i> کلید Files</span><input type="password" name="files_api_key" placeholder="{{ $secretStatus['files'] ? 'کلید تنظیم شده است؛ برای تغییر وارد کنید' : 'کلید سرویس Files' }}" autocomplete="new-password"><small>برای سرویس فایل و ورودی‌های بزرگ.</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-microchip"></i> مدل اصلی Gemini</span><input name="gemini_model" value="{{ $settings['gemini_model'] }}"></label>
                <label class="modern-field"><span><i class="fa-solid fa-shuffle"></i> مدل fallback</span><input name="gemini_fallback_model" value="{{ $settings['gemini_fallback_model'] ?? '' }}" placeholder="اختیاری؛ مثلاً یک مدل پشتیبان"></label>
                <label class="modern-field"><span><i class="fa-solid fa-file-arrow-up"></i> سقف فایل</span><input type="number" name="max_file_mb" value="{{ $settings['max_file_mb'] }}" min="1"><small>مگابایت</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-bolt"></i> درخواست AI روزانه</span><input type="number" name="daily_ai_requests" value="{{ $settings['daily_ai_requests'] }}" min="0"><small>برای کاربر عادی</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-gift"></i> صفحه رایگان هفتگی</span><input type="number" name="weekly_free_pages" value="{{ $settings['weekly_free_pages'] }}" min="0"><small>برای کاربر عادی</small></label>
                <div class="settings-submit"><button class="admin-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره تنظیمات</button></div>
            </form>
        </section>
@endif

@if($console->sectionAllowed(auth()->user(), 'ai_providers'))
        <section id="groq-settings" class="admin-section admin-panel-wide">
            <div class="admin-panel-head">
                <div><span class="panel-eyebrow">FREE-FIRST ASSISTANT</span><h2>موتور همراه هوشمند و تشخیص گفتار</h2>
                    <p>یک کلید Groq برای گفت‌وگوی دستیار و تبدیل صدای ضبط‌شده به متن استفاده می‌شود. کلید در سرور رمزنگاری می‌شود و به مرورگر ارسال نمی‌شود.</p></div>
                <span class="status-chip {{ $secretStatus['groq'] ? 'ok' : 'warn' }}"><i class="fa-solid fa-circle"></i>{{ $secretStatus['groq'] ? 'کلید تنظیم شده' : 'نیازمند کلید' }}</span>
            </div>
            <form method="post" action="{{ route('admin.providers.groq.update') }}" class="settings-modern">
                @csrf
                <label class="modern-field secret" style="grid-column:1/-1">
                    <span><i class="fa-solid fa-key"></i> کلید API سرویس Groq</span>
                    <input type="password" name="voice_groq_key" placeholder="{{ $secretStatus['groq'] ? 'کلید ذخیره شده؛ برای جایگزینی وارد کنید' : 'کلید Groq را اینجا وارد کنید' }}" autocomplete="new-password">
                    <small>خالی گذاشتن این فیلد کلید فعلی را حفظ می‌کند. <a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">ساخت کلید در پنل رسمی Groq</a></small>
                </label>
                <label class="modern-field">
                    <span><i class="fa-solid fa-microchip"></i> شناسه مدل گفت‌وگو</span>
                    <input name="assistant_groq_model" value="{{ $settings['assistant_groq_model'] ?? 'openai/gpt-oss-120b' }}" required maxlength="120">
                    <small>پیش‌فرض: openai/gpt-oss-120b؛ مدل باید در حساب Groq شما فعال باشد.</small>
                </label>
                <div class="settings-submit" style="grid-column:1/-1;display:flex;gap:12px;flex-wrap:wrap">
                    <button class="admin-primary"><i class="fa-solid fa-shield-halved"></i> ذخیره تنظیمات Groq</button>
                    <a class="admin-outline" href="{{ route('admin.providers.groq.test') }}" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-plug-circle-check"></i> آزمایش اتصال و مدل</a>
                </div>
            </form>
            <p class="admin-help-text">رایگان بودن دائمی تضمین‌شده نیست؛ محدودیت‌های پلن رایگان و مدل‌ها تابع سیاست و سهمیه حساب ارائه‌دهنده است. صدای فارسی خروجی فعلاً از صدای دستگاه/مرورگر استفاده می‌کند.</p>
        </section>
@endif

@if($console->sectionAllowed(auth()->user(), 'voice'))
        <section id="voice-settings" class="admin-section admin-panel-wide">
            <div class="admin-panel-head">
                <div>
                    <span class="panel-eyebrow">VOICE AI GATEWAY</span>
                    <h2>کلیدها و حساب‌های تایپ صوتی</h2>
                    <p>کلیدها رمزنگاری‌شده در دیتابیس نگهداری می‌شوند. مقدار ذخیره‌شده هرگز دوباره در صفحه نمایش داده نمی‌شود.</p>
                </div>
            </div>
            <form method="post" action="{{ route('admin.voice.providers.update') }}" class="settings-modern">
                @csrf
                <div class="modern-field" style="grid-column:1/-1">
                    <span><i class="fa-solid fa-wave-square"></i> Gladia — تایپ زنده</span>
                    <input type="text" name="gladia_api_key" placeholder="{{ $gladia && filled($gladia->credentials_array['api_key'] ?? null) ? 'کلید Gladia تنظیم شده؛ برای تغییر وارد کنید' : 'Gladia API Key' }}" autocomplete="off">
                    <small>موتور اصلی پیشنهادی برای جریان زنده فارسی. فعال: <input type="checkbox" name="gladia_enabled" value="1" {{ $gladia?->enabled ? 'checked' : '' }}> · سقف ثانیه ماهانه: <input type="number" name="gladia_quota_limit_seconds" value="{{ $gladia?->quota_limit_seconds ?? 36000 }}" min="0" style="max-width:180px"></small>
                </div>
                <div class="modern-field" style="grid-column:1/-1">
                    <span><i class="fa-solid fa-cloud"></i> Azure Speech — موتور پشتیبان</span>
                    <input type="text" name="azure_subscription_key" placeholder="{{ $azure && filled($azure->credentials_array['subscription_key'] ?? null) ? 'کلید Azure تنظیم شده؛ برای تغییر وارد کنید' : 'Azure Speech subscription key' }}" autocomplete="off">
                    <input type="text" name="azure_region" value="{{ $azure->credentials_array['region'] ?? '' }}" placeholder="Azure region مانند eastus">
                    <small>برای failover در صورت خطای موتور اصلی. فعال: <input type="checkbox" name="azure_enabled" value="1" {{ $azure?->enabled ? 'checked' : '' }}> · سقف ثانیه: <input type="number" name="azure_quota_limit_seconds" value="{{ $azure?->quota_limit_seconds ?? 0 }}" min="0" style="max-width:180px"></small>
                </div>
                <div class="modern-field" style="grid-column:1/-1">
                    <span><i class="fa-brands fa-google"></i> Google Cloud Speech — موتور پشتیبان</span>
                    <input type="text" name="google_project_id" value="{{ $google->credentials_array['project_id'] ?? '' }}" placeholder="Google Cloud Project ID">
                    <input type="text" name="google_client_email" value="{{ $google->credentials_array['client_email'] ?? '' }}" placeholder="Service Account client email">
                    <textarea name="google_private_key" rows="5" placeholder="{{ $google && filled($google->credentials_array['private_key'] ?? null) ? 'Private key تنظیم شده؛ برای تغییر وارد کنید' : '-----BEGIN PRIVATE KEY----- ...' }}" autocomplete="off"></textarea>
                    <input type="text" name="google_region" value="{{ $google->metadata['region'] ?? 'us' }}" placeholder="Google Speech region">
                    <small>Streaming واقعی Google Cloud از مسیر gRPC انجام می‌شود. فعال: <input type="checkbox" name="google_enabled" value="1" {{ $google?->enabled ? 'checked' : '' }}> · سقف ثانیه: <input type="number" name="google_quota_limit_seconds" value="{{ $google?->quota_limit_seconds ?? 0 }}" min="0" style="max-width:180px"></small>
                </div>
                <div class="settings-submit" style="grid-column:1/-1">
                    <button class="admin-primary"><i class="fa-solid fa-shield-halved"></i> ذخیره حساب‌های صوتی</button>
                </div>
            </form>
        </section>
@endif

@if($console->sectionAllowed(auth()->user(), 'settings'))
        <section class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">CAPABILITIES</span><h2>قابلیت‌های پیش‌فرض کاربران</h2><p>این کنترل‌ها به صورت مرکزی اعمال می‌شوند و برای کاربران قابل override هستند.</p></div></div>
            <form method="post" action="{{ route('admin.settings.update') }}" class="capability-modern">
                @csrf
                @foreach(['can_type'=>'تایپ و ویرایش','can_ai'=>'پردازش Gemini','can_voice'=>'تایپ صوتی','can_export_docx'=>'خروجی Word','can_export_pdf'=>'خروجی PDF','can_feedback'=>'ثبت بازخورد','can_support'=>'پشتیبانی'] as $key=>$label)
                    <label class="capability-item"><span><i class="fa-solid {{ $key==='can_ai'?'fa-sparkles':($key==='can_voice'?'fa-microphone':($key==='can_support'?'fa-headset':'fa-check')) }}"></i>{{ $label }}</span><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" {{ filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}><b></b></label>
                @endforeach
                <div class="settings-submit"><button class="admin-outline"><i class="fa-solid fa-check-double"></i> ذخیره دسترسی‌ها</button></div>
            </form>
        </section>
@endif

@if($console->sectionAllowed(auth()->user(), 'users'))
        <section id="users" class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">IDENTITY</span><h2>کاربران و دسترسی اختصاصی</h2><p>کنترل وضعیت و ظرفیت مصرف هر کاربر بدون خروج از کنسول.</p></div><span class="count-chip">{{ $users->count() }} کاربر اخیر</span></div>
            <div class="user-modern-list">
                @forelse($users as $u)
                    @php $cap=app(\App\Services\CapabilityService::class)->forUser($u); @endphp
                    <article class="user-modern-row">
                        <div class="user-identity"><span class="user-modern-avatar">{{ mb_substr($u->name ?: 'ک',0,1) }}</span><div><b>{{ $u->name ?: 'کاربر بدون نام' }}</b><small>{{ $u->mobile }} @if($u->email) · {{ $u->email }} @endif</small></div></div>
                        <div class="user-state"><span class="state-badge {{ $u->is_blocked ? 'blocked' : 'active' }}">{{ $u->is_blocked ? 'مسدود' : 'فعال' }}</span><span class="role-badge">{{ $u->role }}</span></div>
                        @if(!$u->isAdmin())
                            <form method="post" action="{{ route('admin.user.capabilities', $u) }}" class="user-modern-controls">
                                @csrf
                                <div class="user-toggles">@foreach(['can_type'=>'تایپ','can_ai'=>'AI','can_voice'=>'صوت','can_export_docx'=>'Word','can_export_pdf'=>'PDF','can_support'=>'پشتیبانی'] as $key=>$label)<label title="{{ $label }}"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" {{ $cap[$key] ? 'checked' : '' }}><span>{{ $label }}</span></label>@endforeach</div>
                                <div class="user-limits"><label>رایگان/هفته<input type="number" name="weekly_free_pages" value="{{ $cap['weekly_free_pages'] }}" min="0"></label><label>MB<input type="number" name="max_file_mb" value="{{ $cap['max_file_mb'] }}" min="1"></label><label>AI/روز<input type="number" name="daily_ai_requests" value="{{ $cap['daily_ai_requests'] }}" min="0"></label></div>
                                <div class="user-actions"><button class="save-mini"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button><button type="submit" formaction="{{ route('admin.user.toggle', $u) }}" formmethod="post" class="block-mini">{{ $u->is_blocked ? 'رفع مسدودی' : 'مسدود کردن' }}</button></div>
                            </form>
                        @else
                            <div class="master-unlimited"><i class="fa-solid fa-infinity"></i> مدیر اصلی · نامحدود</div>
                        @endif
                    </article>
                @empty
                    <div class="admin-empty">هنوز کاربری ثبت نشده است.</div>
                @endforelse
            </div>
        </section>
@endif

        @if($console->sectionAllowed(auth()->user(), 'announcements') || $console->sectionAllowed(auth()->user(), 'pricing'))
        <section id="content" class="admin-section-grid">
@if($console->sectionAllowed(auth()->user(), 'announcements'))
            <article class="admin-panel content-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">BROADCAST</span><h2>مرکز اعلان</h2><p>انتشار پیام عمومی و کنترل وضعیت اعلان‌ها.</p></div><span class="count-chip">{{ $announcements->count() }}</span></div>
                <form method="post" action="{{ route('admin.announcements.store') }}" class="announcement-modern">
                    @csrf
                    <label>عنوان<input name="title" required maxlength="180" placeholder="عنوان اعلان"></label>
                    <label>نوع<select name="type"><option value="info">اطلاع‌رسانی</option><option value="success">موفقیت</option><option value="warning">هشدار</option><option value="danger">مهم</option></select></label>
                    <label class="full">متن اعلان<textarea name="body" rows="3" required maxlength="5000" placeholder="پیام کوتاه و واضح برای کاربران"></textarea></label>
                    <label class="check-line"><input type="checkbox" name="is_active" value="1" checked> همین حالا فعال باشد</label>
                    <button class="admin-primary"><i class="fa-solid fa-paper-plane"></i> انتشار اعلان</button>
                </form>
                <div class="announcement-modern-list">@forelse($announcements->take(5) as $announcement)<div class="announcement-modern-row"><span class="announcement-dot type-{{ $announcement->type }}"></span><div><b>{{ $announcement->title }}</b><small>{{ \Illuminate\Support\Str::limit($announcement->body,100) }}</small></div><span class="announcement-state {{ $announcement->is_active ? 'on' : 'off' }}">{{ $announcement->is_active ? 'فعال' : 'خاموش' }}</span><form method="post" action="{{ route('admin.announcements.toggle', $announcement) }}">@csrf<button aria-label="تغییر وضعیت"><i class="fa-solid fa-power-off"></i></button></form></div>@empty<div class="admin-empty">اعلانی ثبت نشده است.</div>@endforelse</div>
            </article>
@endif
@if($console->sectionAllowed(auth()->user(), 'pricing'))
            <article id="pricing" class="admin-panel pricing-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">PRICING ENGINE</span><h2>قیمت‌گذاری</h2><p>قواعد فعلی قیمت‌گذاری از backend کنترل می‌شوند.</p></div></div>
                <div class="pricing-modern-list">@foreach($rules as $rule)<form method="post" action="{{ route('admin.pricing.update') }}" class="pricing-modern-row">@csrf<input type="hidden" name="key" value="{{ $rule->key }}"><label><span>عنوان</span><input name="label" value="{{ $rule->label }}"></label><label><span>مقدار</span><input type="number" name="value" value="{{ $rule->value }}" min="0"></label><button aria-label="ذخیره"><i class="fa-solid fa-floppy-disk"></i></button></form>@endforeach</div>
            </article>
@endif
        </section>
@endif

    @if($scope === 'global')
        <section class="admin-section admin-panel-wide" id="system-health">
            <div class="admin-panel-head">
                <div><span class="panel-eyebrow">RUNTIME HEALTH</span><h2>وضعیت زیرساخت و اتصال‌های داخلی</h2><p>این شاخص‌ها هنگام بارگذاری داشبورد بررسی می‌شوند؛ وضعیت سرویس‌های بیرونی جداگانه در بخش اتصال‌ها گزارش می‌شود.</p></div>
            </div>
            <div class="admin-metric-grid">
                <article class="metric-card {{ $systemHealth['db_ok'] ? 'metric-green' : 'metric-orange' }}"><div class="metric-top"><span>پایگاه داده</span><i class="fa-solid fa-database"></i></div><strong>{{ $systemHealth['db_ok'] ? 'دسترس‌پذیر' : 'خطا' }}</strong><small>درایور: {{ $systemHealth['driver'] }}</small></article>
                <article class="metric-card {{ $systemHealth['cache_ok'] ? 'metric-green' : 'metric-orange' }}"><div class="metric-top"><span>Cache</span><i class="fa-solid fa-bolt"></i></div><strong>{{ $systemHealth['cache_ok'] ? 'دسترس‌پذیر' : 'خطا' }}</strong><small>آزمون نوشتن، خواندن و پاک‌سازی موقت</small></article>
                <article class="metric-card {{ $systemHealth['storage_writable'] ? 'metric-green' : 'metric-orange' }}"><div class="metric-top"><span>ذخیره‌سازی برنامه</span><i class="fa-solid fa-hard-drive"></i></div><strong>{{ $systemHealth['storage_writable'] ? 'قابل نوشتن' : 'غیرقابل نوشتن' }}</strong><small>بررسی مجوز مسیر storage</small></article>
                <article class="metric-card {{ $systemHealth['private_storage_ok'] ? 'metric-green' : 'metric-orange' }}"><div class="metric-top"><span>فضای خصوصی فایل‌ها</span><i class="fa-solid fa-lock"></i></div><strong>{{ $systemHealth['private_storage_ok'] ? 'خواندن/نوشتن سالم' : 'خطا' }}</strong><small>آزمون واقعی نوشتن، خواندن و حذف فایل موقت</small></article>
                <article class="metric-card metric-violet"><div class="metric-top"><span>صف پردازش</span><i class="fa-solid fa-list-check"></i></div><strong>{{ $systemHealth['queue_connection'] }}</strong><small>درایور پیکربندی‌شده؛ بدون ادعای probe زنده</small></article>
            </div>
            <p class="health-footnote">محیط: {{ $systemHealth['env'] }} · PHP {{ $systemHealth['php'] }} · Laravel {{ $systemHealth['laravel'] }} · Debug: {{ $systemHealth['debug'] ? 'روشن' : 'خاموش' }}</p>
        </section>
        @endif
@if($console->sectionAllowed(auth()->user(), 'access') || $console->sectionAllowed(auth()->user(), 'organizations') || $console->sectionAllowed(auth()->user(), 'integrations') || $console->sectionAllowed(auth()->user(), 'analytics') || $console->sectionAllowed(auth()->user(), 'audit') || $console->sectionAllowed(auth()->user(), 'finance') || $console->sectionAllowed(auth()->user(), 'emails') || $console->sectionAllowed(auth()->user(), 'social'))
<section class="admin-section admin-panel-wide" id="platform-control">
<div class="admin-panel-head"><div><span class="panel-eyebrow">FARAST CONTROL CENTER</span><h2>مدیریت کامل پلتفرم</h2><p>ماژول‌های عملیاتی واقعی از همین کنسول قابل دسترسی‌اند؛ وضعیت‌های «planned» بدون مسیر عملیاتی به‌عنوان قابلیت کامل معرفی نمی‌شوند.</p></div></div>
<div class="ops-grid">
@if($console->sectionAllowed(auth()->user(), 'access'))<a href="{{ route('admin.access') }}"><span class="ops-icon purple"><i class="fa-solid fa-user-shield"></i></span><div><b>RBAC</b><small>نقش‌ها و مجوزهای granular</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'organizations'))<a href="{{ route('admin.organizations') }}"><span class="ops-icon"><i class="fa-solid fa-building"></i></span><div><b>{{ number_format($stats['organizations']) }}</b><small>سازمان · {{ number_format($stats['teams']) }} تیم</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'integrations'))<a href="{{ route('admin.integrations') }}"><span class="ops-icon cyan"><i class="fa-solid fa-plug"></i></span><div><b>Integrations</b><small>Gemini · Voice · Canva OAuth</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'analytics'))<a href="{{ route('admin.analytics') }}"><span class="ops-icon orange"><i class="fa-solid fa-chart-line"></i></span><div><b>{{ number_format($stats['voice_requests']) }}</b><small>{{ number_format($stats['voice_seconds']) }} ثانیه صوت ثبت‌شده</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'audit'))<a href="{{ route('admin.audit') }}"><span class="ops-icon"><i class="fa-solid fa-shield-halved"></i></span><div><b>{{ number_format($stats['audit_events']) }}</b><small>رویداد ممیزی</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'finance'))<a href="{{ route('admin.finance') }}"><span class="ops-icon purple"><i class="fa-solid fa-wallet"></i></span><div><b>Finance</b><small>تراکنش، کیف پول و پرداخت</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'emails'))<a href="{{ route('admin.emails') }}"><span class="ops-icon cyan"><i class="fa-solid fa-envelope"></i></span><div><b>Email</b><small>تنظیم و تست ایمیل</small></div></a>@endif
@if($console->sectionAllowed(auth()->user(), 'social'))<a href="{{ route('admin.social') }}"><span class="ops-icon orange"><i class="fa-solid fa-bullhorn"></i></span><div><b>Content</b><small>اعلان و شبکه‌های اجتماعی</small></div></a>@endif
</div></section>
@endif
@endsection


@push('styles')
<style>
.farast-admin-nav-create-product{margin:5px 10px 12px;padding:11px 13px!important;border:1px solid rgba(69,145,255,.28);border-radius:12px;background:linear-gradient(115deg,rgba(45,112,219,.16),rgba(67,151,231,.07));color:#d9e9ff!important;font-weight:800;display:flex!important;align-items:center;gap:10px}
.farast-admin-nav-create-product i{color:#70b4ff;font-size:1rem}
.farast-admin-nav-create-product b{margin-inline-start:auto;width:22px;height:22px;display:grid;place-items:center;border-radius:7px;background:#2e73d2;color:white;font-size:1rem}
.farast-admin-nav a[aria-current="page"]{outline:1px solid rgba(111,174,255,.22)}
.hero-copy h1{font-size:25px;line-height:1.55;margin:13px 0 7px;letter-spacing:-.5px}
.admin-action-metrics{margin-top:-5px}
.admin-action-metric{display:block;min-width:0;text-decoration:none;color:inherit;transition:transform .18s ease,box-shadow .18s ease}
.admin-action-metric:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(16,35,70,.12)}
.admin-action-metric:focus-visible{outline:2px solid #3679e8;outline-offset:3px}
.admin-action-metric strong{display:block}

.admin-activity-list{display:grid;gap:0}
.admin-activity-row{display:flex;align-items:center;gap:12px;padding:12px 2px;border-top:1px solid #edf1f7;min-width:0}
.admin-activity-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:11px;background:#edf4ff;color:#315fae}
.admin-activity-row>div{display:grid;gap:4px;min-width:0}
.admin-activity-row strong{font-size:11px;color:#253550;overflow-wrap:anywhere}
.admin-activity-row small{font-size:10px;color:#8390a5}
@media(max-width:760px){.farast-admin-nav-create-product{margin-inline:8px}}
</style>
@endpush
