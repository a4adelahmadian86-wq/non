<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#0b1734"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="mobile-web-app-title" content="فراست">
<title>{{ $title ?? 'فراست' }}</title>
@stack('head')
@php
$isEditor=request()->is('editor'); $isAdmin=request()->is('admin*'); $isDashboard=request()->routeIs('dashboard')||request()->routeIs('admin.*')||request()->routeIs('support')||request()->routeIs('support.*'); $isAuthPage=request()->is('login*')||request()->is('register')||request()->is('forgot-password*');
$footerSocial=[]; $farastCapabilities=null;
if($isEditor&&auth()->check()){$farastCapabilities=app(\App\Services\CapabilityService::class)->forUser(auth()->user());}
if(!$isEditor&&!$isAdmin&&!$isDashboard){if(\Illuminate\Support\Facades\Schema::hasTable('site_settings')){$footerRaw=\App\Models\SiteSetting::read('social_links','[]');$decodedSocial=json_decode((string)$footerRaw,true);if(is_array($decodedSocial)){$footerSocial=array_values(array_filter($decodedSocial,static fn($item)=>is_array($item)&&filter_var($item['url']??'',FILTER_VALIDATE_URL)));}}}
@endphp
<link rel="preconnect" href="https://cdnjs.cloudflare.com"><link rel="preconnect" href="https://cdn.jsdelivr.net"><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="/css/farast.css"><link rel="stylesheet" href="/css/ui-polish.css"><link rel="stylesheet" href="/css/site-premium.css?v=20261107nav1"><link rel="stylesheet" href="/css/farast-app.css"><link rel="stylesheet" href="/css/finance.css">
@if(request()->routeIs('home'))<link rel="stylesheet" href="{{ asset('css/home.css') }}">@endif
@if($isDashboard)<link rel="stylesheet" href="/css/dashboard-navigation.css">@endif
@if($isEditor)
<meta name="farast-capabilities" content='@json($farastCapabilities)'><link rel="stylesheet" href="/css/editor-architecture.css?v=20261014e"><link rel="stylesheet" href="/css/editor-layout.css?v=20261014a"><link rel="stylesheet" href="/css/voice.css?v=20261010">
@endif
@if($isAdmin)<link rel="stylesheet" href="/css/admin.css?v=20261107-health1"><link rel="stylesheet" href="/css/farast-control.css">@if(request()->routeIs('admin.store.*'))<link rel="stylesheet" href="/css/store-admin.css?v=20261107-workspace1">@endif@endif
@if($isAuthPage)<link rel="stylesheet" href="/css/auth.css">@endif
<link rel="stylesheet" href="/css/farast-assistant.css?v=20261106micStates2">
@stack('styles')
</head>
<body data-assistant-auth="{{ auth()->check() ? '1' : '0' }}" class="{{ $isEditor?'editor-page-body':'' }} {{ $isAuthPage?'auth-page':'' }}">
@if(!$isEditor&&!$isAdmin&&!$isDashboard)
<header class="top premium-header" data-app-header>
  <div class="header-inner">
    <a class="brand premium-brand" href="{{ route('home') }}" aria-label="فراست">
      <span class="brand-mark farast-symbol" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></span>
      <span><b>FARAST</b><small>فراست | فروش فایل و خدمات هوشمند</small></span>
    </a>
    <div class="header-search" role="search">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input id="farastGlobalSearch" type="search" placeholder="جستجوی فایل، خدمت یا موضوع..." autocomplete="off" aria-label="جستجو در سایت">
      <button type="button" id="farastVoiceSearch" aria-label="جستجوی صوتی"><i class="fa-solid fa-microphone"></i></button>
    </div>
    <nav class="main-nav" id="primaryNavigation" aria-label="ناوبری اصلی">
      <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif><i class="fa-solid fa-house"></i><span>خانه</span></a>
      <a href="{{ route('store') }}" @if(request()->routeIs('store*')) aria-current="page" @endif><i class="fa-solid fa-store"></i><span>فروشگاه</span></a>
      <a href="{{ route('blog') }}" @if(request()->routeIs('blog*')) aria-current="page" @endif><i class="fa-solid fa-book-open"></i><span>مجله</span></a>
      <a href="{{ route('editor') }}"><i class="fa-solid fa-pen-ruler"></i><span>تایپ و خدمات</span></a>
      <a href="{{ route('pricing') }}"><i class="fa-solid fa-tags"></i><span>قیمت</span></a>
      @auth
        <a href="/wishlist"><i class="fa-regular fa-heart"></i><span>علاقه</span></a>
        <a href="{{ route('dashboard') }}"><i class="fa-solid fa-table-cells-large"></i><span>فضای من</span></a>
        <a href="{{ route('support') }}"><i class="fa-solid fa-headset"></i><span>پشتیبانی</span></a>
        @if(auth()->user()->isAdmin())<a href="/admin"><i class="fa-solid fa-user-shield"></i><span>مدیریت</span></a>@endif
      @else
        <a href="{{ route('login') }}"><i class="fa-solid fa-right-to-bracket"></i><span>ورود</span></a>
      @endauth
    </nav>
    <div class="header-actions">
      <button type="button" class="mobile-nav-toggle" data-mobile-nav-toggle aria-expanded="false" aria-controls="primaryNavigation" aria-label="باز کردن منوی سایت"><i class="fa-solid fa-bars"></i></button>
      <button type="button" class="header-icon-button" id="farastCartButton" aria-label="سبد خرید"><i class="fa-solid fa-bag-shopping"></i><b id="farastCartCount">0</b></button>
      <a class="header-cta" href="{{ route('store') }}"><i class="fa-solid fa-store"></i><span>فروشگاه</span></a>
    </div>
  </div>
</header>

@endif
<main id="app-main" class="{{ $isDashboard ? 'dashboard-shell' : '' }}">
@if($isDashboard && auth()->check())<div class="dashboard-shell-inner">@include('components.dashboard-navigation')@endif
@yield('content')
@if($isDashboard && auth()->check())</div>@endif
</main>
@if(!$isEditor&&!$isAdmin&&!$isDashboard)<aside class="farast-cart-drawer" id="farastCartDrawer" aria-hidden="true"><div class="farast-cart-head"><strong>سبد خرید</strong><button type="button" data-cart-close aria-label="بستن"><i class="fa-solid fa-xmark"></i></button></div><div class="farast-cart-body" id="farastCartBody"></div><div class="farast-cart-foot"><span>جمع</span><strong id="farastCartTotal">۰ تومان</strong><button type="button" id="farastCartCheckout" disabled>ادامه پرداخت</button></div></aside><div class="farast-cart-backdrop" id="farastCartBackdrop" hidden></div><div class="farast-connectivity" id="farastConnectivity" aria-live="polite" hidden><div class="farast-connectivity-card"><div class="farast-connectivity-copy"><strong id="farastConnectivityTitle">اتصال اینترنت در دسترس نیست</strong><span id="farastConnectivityMessage">بخش‌های ذخیره‌شده در دسترس می‌مانند.</span></div><button type="button" id="farastConnectivityRetry" aria-label="تلاش دوباره"><i class="fa-solid fa-rotate-right"></i></button></div></div><button type="button" id="farastSoundToggle" class="farast-sound-toggle" aria-label="فعال یا غیرفعال کردن صدای رابط" aria-pressed="false"><i class="fa-solid fa-volume-high"></i></button>@endif
@if(!$isEditor&&!$isAdmin&&!$isDashboard)<footer class="site-footer" dir="rtl">
  <div class="footer-glow" aria-hidden="true"></div>
  <div class="footer-top">
    <div class="footer-about">
      <div class="footer-brand"><span class="brand-mark farast-symbol"><i></i><i></i><i></i><i></i><b></b></span><b>فراست</b></div>
      <p>یک فضای یکپارچه برای نوشتن، تبدیل، ویرایش و مدیریت اسناد؛ در کنار کتابخانه‌ای از فایل‌ها و منابع دیجیتال.</p>
      <div class="footer-social" aria-label="شبکه‌های اجتماعی">
        @foreach($footerSocial as $social)
          <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $social['title'] ?? $social['name'] ?? 'شبکه اجتماعی فراست' }}"><i class="{{ $social['icon'] ?? 'fa-solid fa-arrow-up-right-from-square' }}"></i></a>
        @endforeach
      </div>
      <div class="footer-brand-note"><i class="fa-solid fa-sparkles"></i> از ایده تا نتیجه، همراه شما</div>
    </div>
    <div class="footer-column"><h3>محصول</h3><a href="{{ route('store') }}">فروشگاه فایل</a><a href="{{ route('editor') }}">تایپ و خدمات</a><a href="{{ route('pricing') }}">قیمت‌گذاری</a><a href="{{ route('cart') }}">سبد خرید</a></div>
    <div class="footer-column"><h3>دانش و راهنما</h3><a href="{{ route('blog') }}">مجله فراست</a><a href="/announcements">تازه‌ها و اطلاعیه‌ها</a><a href="/privacy">حریم خصوصی</a><a href="/terms">قوانین استفاده</a></div>
    <div class="footer-column footer-column--account"><h3>حساب و پشتیبانی</h3><a href="{{ route('dashboard') }}">فضای کاربری</a><a href="/wallet">کیف پول</a><a href="/wishlist">علاقه‌مندی‌ها</a><a href="/support">مرکز پشتیبانی</a><a href="#" data-open-support>گفت‌وگو با پشتیبانی</a><div class="footer-trust"><i class="fa-solid fa-shield-halved"></i><span>شفافیت در خرید، دسترسی آسان به فایل‌ها و پشتیبانی در مسیر استفاده</span></div></div>
  </div>
  <div class="footer-bottom"><span>© {{ now()->year }} فراست — تمامی حقوق محفوظ است.</span><div><a href="/privacy">حریم خصوصی</a><a href="/terms">قوانین استفاده</a><a href="/refund-policy">شرایط بازگشت وجه</a><a href="{{ route('blog') }}">مجله</a></div><a class="footer-back-top" href="#app-main"><i class="fa-solid fa-arrow-up"></i> بازگشت به بالا</a></div>
</footer>@endif
@include('components.support-chat-widget')
<script src="/js/farast-tab-lock.js"></script><script src="/js/farast.js"></script><script src="/js/farast-sound.js"></script>@if(!$isAdmin&&!$isDashboard)<script src="/js/farast-app.js"></script><script src="/js/store-cart.js?v=20260929c1"></script>@endif
<script src="/js/farast-assistant.js?v=20261107voiceSession1"></script>
@if($isEditor)<script src="/js/editor-layout.js?v=20261014a"></script><script src="/js/editor-core.js?v=20261014g"></script><script src="/js/editor-agent.js?v=20261010a"></script><script src="/js/farast-voice.js?v=20261031serveronly5"></script>@endif
@stack('scripts')<script>
(() => {
  const toggle = document.querySelector('[data-mobile-nav-toggle]');
  const nav = document.getElementById('primaryNavigation');
  if (!toggle || !nav) return;
  const close = () => {
    nav.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'باز کردن منوی سایت');
    toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
  };
  toggle.addEventListener('click', () => {
    const open = !nav.classList.contains('is-open');
    nav.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'بستن منوی سایت' : 'باز کردن منوی سایت');
    toggle.innerHTML = open ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-bars"></i>';
  });
  nav.querySelectorAll('a').forEach(link => link.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  document.addEventListener('click', event => {
    if (!nav.contains(event.target) && !toggle.contains(event.target)) close();
  });
})();
</script><script>document.documentElement.classList.add('js-ready');</script>
</body></html>
