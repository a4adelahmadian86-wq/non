@extends('layouts.admin')
@section('admin-content')
<div class="farast-control-page" dir="rtl">
<header><div><span class="eyebrow">INTEGRATIONS / API</span><h1>یکپارچه‌سازی و سرویس‌دهنده‌ها</h1><p>اعتبارنامه‌ها فقط سمت سرور نگهداری می‌شوند و مقدار secret هرگز دوباره نمایش داده نمی‌شود.</p></div><a href="{{ route('admin.index') }}">بازگشت به مرکز عملیات</a></header>
@if(session('status'))<div class="control-success">{{ session('status') }}</div>@endif
<section class="control-grid">
<article class="control-card"><div class="provider-head"><strong>Canva Connect</strong><span class="{{ $canvaConfigured?'ok':'warn' }}">{{ $canvaConfigured?'تنظیم شده':'NOT CONFIGURED' }}</span></div>
<p>اتصال رسمی OAuth 2.0 + PKCE برای دسترسی کاربر به طراحی‌ها و جریان‌های Canva. پس از اتصال، مسیرهای server-side برای فهرست طراحی‌ها، ایجاد طراحی، import فایل و export job در دسترس‌اند.</p>
<form method="post" action="{{ route('admin.integrations.canva.save') }}">@csrf
<label>Client ID<input name="canva_client_id" placeholder="Client ID"></label>
<label>Client Secret<input type="password" name="canva_client_secret" placeholder="{{ $canvaConfigured?'Secret تنظیم شده؛ برای جایگزینی وارد کنید':'Client Secret' }}" autocomplete="new-password"></label>
<label>Redirect URI<input name="canva_redirect_uri" type="url" placeholder="https://example.com/integrations/canva/callback"></label>
<label>Scopes<input name="canva_scopes" value="design:meta:read design:content:read design:content:write asset:read asset:write"></label>
<button type="submit">ذخیره تنظیمات Canva</button></form>
<div class="control-status">{{ $canvaConnected?'اتصال کاربر فعلی برقرار است.':'اتصال کاربر فعلی برقرار نیست.' }}</div>
@if($canvaConfigured)<a class="control-link" href="{{ route('canva.connect') }}">اتصال / ورود به Canva</a>@endif
</article>
<article class="control-card">
<div class="provider-head"><strong>FARAST AI Gateway</strong><span class="ok">مسیر واحد مدل‌ها</span></div>
<p>سایت مستقیماً به Gemini، Groq یا API مدل‌ها وصل نمی‌شود. همهٔ درخواست‌های متن، بازبینی محصول، تصویر و صوت از Worker احراز هویت‌شده عبور می‌کنند. کلیدهای Cloudflare فقط در خود Worker می‌مانند؛ سایت فقط URL و token درگاه را نگه می‌دارد.</p>
<p>مدل‌ها در Worker ثابت شده‌اند: GPT-OSS 20B برای متن، GPT-OSS 120B برای بازبینی، Moondream برای تصویر و Whisper برای صوت. سهمیهٔ رایگان روزانه بین همهٔ این مسیرها مشترک است.</p>
<form method="post" action="{{ route('admin.providers.ai.update') }}">@csrf
<label>نشانی اصلی Cloudflare Worker<input type="url" name="ai_gateway_url" value="{{ \App\Models\SiteSetting::read('ai_gateway_url',config('services.ai_gateway.url','')) }}" placeholder="https://...workers.dev" maxlength="255"></label>
<label>توکن درگاه اصلی<input type="password" name="ai_gateway_token" placeholder="{{ filled(\App\Models\SiteSetting::read('ai_gateway_token')) ? 'توکن ذخیره شده؛ برای جایگزینی وارد کنید' : 'Gateway token' }}" autocomplete="new-password"></label>
<label>نشانی جایگزین Runflare Gateway<input type="url" name="ai_gateway_fallback_url" value="{{ \App\Models\SiteSetting::read('ai_gateway_fallback_url',config('services.ai_gateway.fallback_url','')) }}" placeholder="https://...runflare..." maxlength="255"></label>
<label>توکن درگاه جایگزین<input type="password" name="ai_gateway_fallback_token" placeholder="{{ filled(\App\Models\SiteSetting::read('ai_gateway_fallback_token')) ? 'توکن جایگزین ذخیره شده؛ برای جایگزینی وارد کنید' : 'Fallback gateway token' }}" autocomplete="new-password"></label>
<button type="submit">ذخیره تنظیمات درگاه FARAST</button></form>
<button type="button" id="farastAiGatewayTestBtn" style="margin-top:8px">تست اتصال درگاه FARAST</button><span id="farastAiGatewayTestMsg" style="margin-inline-start:8px" role="status" aria-live="polite"></span>
<script>document.getElementById('farastAiGatewayTestBtn').addEventListener('click',async()=>{const m=document.getElementById('farastAiGatewayTestMsg');m.textContent='در حال بررسی درگاه…';try{const r=await fetch('{{ route("admin.providers.ai.test") }}',{headers:{'Accept':'application/json'},credentials:'same-origin'});const j=await r.json();m.textContent=j.message||'';m.style.color=j.ready?'green':(j.ok?'#a66a00':'#c62828');}catch{m.textContent='خطای شبکه';m.style.color='#c62828';}});</script>
</article>
<article class="control-card"><div class="provider-head"><strong>Voice Router</strong><span class="ok">اولویت‌دهی پویا بر اساس کیفیت، ظرفیت، پایداری و تأخیر</span></div><p>حساب‌های صوتی موجود در جدول provider orchestration مدیریت می‌شوند؛ quota و سلامت در همان معماری ثبت می‌شوند.</p><a class="control-link" href="{{ route('admin.index') }}#voice-settings">مدیریت حساب‌های صوتی</a></article>
</section>
<section class="control-card" style="margin-top:18px">
    <div class="provider-head"><strong>AI &amp; Service Providers</strong><span class="ok">Unified registry</span></div>
    <p>وضعیت اعتبارنامه، دسترسی API، تأیید اتصال و آمادگی مدل به‌صورت جداگانه نمایش داده می‌شود؛ هیچ‌کدام صرفاً از روی وجود کلید سبز اعلام نمی‌شوند.</p>
    <div style="overflow:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr>
                <th>Provider</th><th>Type</th><th>Model</th><th>Capabilities</th><th>Status</th>
                <th>API</th><th>تأیید اتصال</th><th>آمادگی مدل</th><th>آمادگی قابلیت‌ها</th><th>آخرین اتصال موفق</th>
                <th>آخرین خطا</th><th>علت آخرین خطا</th><th>Priority</th><th>Quota</th><th>جزئیات</th>
            </tr></thead>
            <tbody>
            @forelse($providers as $provider)
                <tr>
                    <td>{{ $provider['name'] }}</td>
                    <td>{{ strtoupper($provider['type']) }}</td>
                    <td>{{ $provider['model'] ?: '—' }}</td>
                    <td>{{ implode(', ', $provider['capabilities']) }}</td>
                    <td>{{ match($provider['status']) { 'healthy' => 'اتصال تأییدشده و آماده', 'unverified' => 'پیکربندی شده · تأییدنشده', 'configured' => 'اعتبارنامه ثبت شده · نیازمند تست', 'unavailable' => 'اعتبارنامه موجود نیست', 'degraded' => 'نیازمند بررسی؛ API یا مدل ناقص', 'stale' => 'آخرین بررسی منقضی شده؛ تست مجدد لازم است', 'unconfigured' => 'اعتبارنامه ناقص', 'disabled' => 'غیرفعال', default => $provider['status'] } }}</td>
                    <td>{{ ($provider['api_reachable'] ?? false) ? 'در دسترس' : 'تأییدنشده / قطع' }}</td>
                    <td>{{ ($provider['connection_verified'] ?? false) ? 'تأییدشده' : 'تأییدنشده' }}</td>
                    <td>{{ ($provider['ready'] ?? false) ? 'آماده' : 'آماده نیست' }}</td>
                    <td>
                        @if(!empty($provider['capability_readiness']))
                            @foreach($provider['capability_readiness'] as $capability => $ready)
                                <span>{{ $capability }}: {{ $ready ? 'آماده' : 'ناموجود' }}</span>@if(!$loop->last) · @endif
                            @endforeach
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $provider['last_success_at'] ?? 'هنوز ثبت نشده' }}</td>
                    <td>{{ $provider['last_failure_at'] ?? '—' }}</td>
                    <td>{{ $provider['last_failure_code'] ?? '—' }}</td>
                    <td>{{ $provider['priority'] }}</td>
                    <td>{{ $provider['quota'] ? number_format($provider['quota']['used']) . ' / ' . ($provider['quota']['limit'] === null ? '∞' : number_format($provider['quota']['limit'])) : '—' }}</td>
                    <td>@if($provider['type'] === 'voice')<a href="{{ route('admin.providers.voice.test', $provider['provider']) }}" target="_blank" rel="noopener noreferrer">آخرین وضعیت ثبت‌شده</a>@else—@endif</td>
                </tr>
            @empty
                <tr><td colspan="15">Provider فعالی ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
</div>
@endsection