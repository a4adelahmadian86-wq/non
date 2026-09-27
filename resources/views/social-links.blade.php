@extends('layouts.app')
@section('content')
<div class="finance-page" dir="rtl"><div class="finance-head"><div><span class="eyebrow">FARAST CONNECT</span><h1>نمادهای ارتباطی</h1><p>همه مسیرهای رسمی ارتباط و شبکه‌های اجتماعی فراست.</p></div><a class="finance-back" href="/"><i class="fa-solid fa-arrow-right"></i> بازگشت</a></div><div class="social-grid">@forelse($links as $link)<a class="social-card" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"><span><i class="{{ $link['icon'] }}"></i></span><div><b>{{ $link['title'] }}</b><small>ورود به صفحه رسمی</small></div><i class="fa-solid fa-arrow-up-right-from-square"></i></a>@empty<div class="checkout-card empty-finance">هنوز نماد ارتباطی تنظیم نشده است.</div>@endforelse</div></div>
@endsection
