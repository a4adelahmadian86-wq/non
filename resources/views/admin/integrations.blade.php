@extends('layouts.app')
@section('content')
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
<article class="control-card"><div class="provider-head"><strong>Gemini</strong><span class="ok">Server-side</span></div><p>مدل، کلید رمزنگاری‌شده، مسیر AI و quota از backend کنترل می‌شود.</p><form method="post" action="{{ route('admin.providers.ai.update') }}">@csrf<label>API Key<input type="password" name="gemini_api_key" placeholder="برای جایگزینی کلید وارد کنید" autocomplete="new-password"></label><label>Model<input name="gemini_model" value="{{ AppModelsSiteSetting::read('gemini_model','gemini-3.8-flash') }}"></label><button type="submit">ذخیره Gemini</button></form></article>
<article class="control-card"><div class="provider-head"><strong>Voice Router</strong><span class="ok">Gladia → Azure → Google</span></div><p>حساب‌های صوتی موجود در جدول provider orchestration مدیریت می‌شوند؛ quota و سلامت در همان معماری ثبت می‌شوند.</p><a class="control-link" href="{{ route('admin.index') }}#voice-settings">مدیریت حساب‌های صوتی</a></article>
</section>
</div>
@endsection