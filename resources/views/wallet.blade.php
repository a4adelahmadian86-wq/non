@extends('layouts.app')
@section('content')
<div class="finance-page" dir="rtl"><div class="finance-head"><div><span class="eyebrow">حساب مالی</span><h1>کیف پول فراست</h1><p>موجودی، تراکنش‌ها و هزینه خروجی‌ها را شفاف و یکجا ببینید.</p></div><a class="finance-back" href="/editor"><i class="fa-solid fa-arrow-right"></i> بازگشت به ویرایشگر</a></div>@if(session('status'))<div class="finance-alert"><i class="fa-solid fa-circle-info"></i>{{ session('status') }}</div>@endif
<div class="wallet-hero"><div><span>موجودی قابل استفاده</span><strong>{{ number_format($wallet->balance_rials) }} <small>ریال</small></strong></div><div class="wallet-icon"><i class="fa-solid fa-wallet"></i></div></div>
<div class="wallet-grid"><section class="checkout-card"><div class="card-title"><span><i class="fa-solid fa-plus"></i></span><div><h2>افزایش موجودی</h2><small>اتصال درگاه بانکی از تنظیمات مالی مدیر انجام می‌شود.</small></div></div>@if($errors->has('topup'))<div class="finance-alert">{{ $errors->first('topup') }}</div>@endif
@if($gatewayEnabled)
<form method="POST" action="{{ route('wallet.topup') }}" class="wallet-topup-form">@csrf
<label for="amount_rials">مبلغ افزایش موجودی (ریال)</label>
<input id="amount_rials" name="amount_rials" type="number" min="10000" max="1000000000" step="1000" value="{{ old('amount_rials', 100000) }}" required>
<small>حداقل ۱۰٬۰۰۰ و حداکثر ۱٬۰۰۰٬۰۰۰٬۰۰۰ ریال؛ موجودی فقط پس از تأیید قطعی درگاه افزایش می‌یابد.</small>
<button type="submit" class="pay-button"><i class="fa-solid fa-lock"></i> پرداخت امن از طریق زرین‌پال</button>
</form>
@else
<div class="credit-note"><i class="fa-solid fa-plug-circle-xmark"></i><p>درگاه آنلاین هنوز در تنظیمات مالی مدیر فعال نشده است. پس از ثبت پذیرندهٔ معتبر زرین‌پال و فعال‌سازی درگاه، امکان افزایش موجودی از همین بخش فراهم می‌شود.</p></div>
<button type="button" class="pay-button" disabled><i class="fa-solid fa-lock"></i> درگاه آنلاین در انتظار تنظیم</button>
@endif</section><section class="checkout-card"><div class="card-title"><span><i class="fa-solid fa-shield-halved"></i></span><div><h2>اعتبار رایگان</h2><small>به شماره موبایل حساب وابسته است.</small></div></div><div class="credit-note"><i class="fa-solid fa-gift"></i><p>اعتبار آزمایشی هر هفته فقط یک‌بار برای هر شماره موبایل قابل مصرف است و هنگام پرداخت نهایی مصرف می‌شود؛ صرفاً تحلیل یا بازبینی باعث سوختن اعتبار نمی‌شود.</p></div></section></div>
<section class="checkout-card transactions"><div class="card-title"><span><i class="fa-solid fa-list"></i></span><div><h2>تراکنش‌های اخیر</h2><small>آخرین ۳۰ رویداد مالی</small></div></div><div class="transaction-list">@forelse($transactions as $tx)<div class="transaction-row"><span class="tx-icon {{ $tx->type==='credit' ? 'credit' : 'debit' }}"><i class="fa-solid {{ $tx->type==='credit' ? 'fa-arrow-down' : 'fa-arrow-up' }}"></i></span><div><b>{{ $tx->description ?: ($tx->type==='credit' ? 'افزایش موجودی' : 'پرداخت') }}</b><small>{{ $tx->created_at?->format('Y/m/d H:i') }}</small></div><strong class="{{ $tx->type==='credit' ? 'credit-text' : 'debit-text' }}">{{ $tx->type==='credit' ? '+' : '-' }}{{ number_format($tx->amount_rials) }} ریال</strong></div>@empty<div class="empty-finance">هنوز تراکنش مالی ثبت نشده است.</div>@endforelse</div></section></div>
@endsection
