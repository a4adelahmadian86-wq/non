@extends('layouts.app')
@section('content')
@php
  $isDeposit = $order->isDeposit();
  $isStore = ($order->pricing_snapshot['kind'] ?? null) === 'store';
  $zarinpalOn = \App\Models\SiteSetting::read('payment_gateway', 'none') === 'zarinpal'
      && filled(\App\Models\SiteSetting::read('gateway_merchant_id'));
  $walletShortfall = max(0, (int) $order->total_rials - (int) $wallet->balance_rials);
@endphp
<div class="finance-page" dir="rtl">
  <div class="finance-head">
    <div>
      <span class="eyebrow">پرداخت امن فراست</span>
      <h1>{{ $isDeposit ? 'تأیید مبلغ شروع سفارش' : ($isStore ? 'پرداخت سفارش فروشگاه' : 'تأیید و پرداخت سفارش') }}</h1>
      <p>{{ $isDeposit ? 'مبلغ لازم برای شروع این سفارش در ادامه نمایش داده شده است.' : ($isStore ? 'می‌توانید از کیف پول یا درگاه مستقیم زرین‌پال پرداخت کنید.' : 'قیمت پیش از پرداخت دوباره از متن نهایی محاسبه می‌شود؛ تغییرات بعد از پرداخت نیازمند محاسبه مجدد است.') }}</p>
    </div>
    <div class="checkout-head-actions">
      <span class="checkout-order-ref"><i class="fa-solid fa-receipt" aria-hidden="true"></i> سفارش #{{ $order->id }}</span>
      <a class="finance-back" href="{{ $isStore ? route('store') : '/wallet' }}"><i class="fa-solid fa-{{ $isStore ? 'store' : 'wallet' }}" aria-hidden="true"></i> {{ $isStore ? 'فروشگاه' : 'کیف پول' }}</a>
    </div>
  </div>

  @if($errors->any())
    <div class="finance-alert danger" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>{{ $errors->first() }}</div>
  @endif

  <div class="checkout-grid">
    <section class="checkout-card" aria-labelledby="checkout-summary-title">
      <div class="card-title"><span><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><div><h2 id="checkout-summary-title">{{ $isDeposit ? 'شروع سفارش' : 'خلاصه سفارش' }}</h2><small>جزئیات مبلغ پیش از پرداخت</small></div></div>
      <div class="invoice-lines">
        @if($isDeposit)
          <div class="total"><span>مبلغ قابل پرداخت برای شروع</span><strong>{{ number_format($order->total_rials) }} ریال</strong></div>
        @else
          <div><span>هزینه خدمات</span><b>{{ number_format($order->subtotal_rials) }} ریال</b></div>
          @if($order->discount_rials > 0)<div><span>{{ $isStore ? 'تخفیف' : 'اعتبار هدیه' }}</span><b class="discount">{{ number_format($order->discount_rials) }}- ریال</b></div>@endif
          @if($order->tax_rials > 0)<div><span>مالیات و عوارض</span><b>{{ number_format($order->tax_rials) }} ریال</b></div>@endif
          @if((int) data_get($order->pricing_snapshot, 'deposit_credit_rials', 0) > 0)<div><span>مبلغ پرداخت‌شده پیشین</span><b class="discount">{{ number_format((int) data_get($order->pricing_snapshot, 'deposit_credit_rials', 0)) }}- ریال</b></div>@endif
          <div class="total"><span>مبلغ قابل پرداخت</span><strong>{{ number_format($order->total_rials) }} ریال</strong></div>
        @endif
      </div>
      <div class="legal-box"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><p>{{ $isStore ? 'پس از پرداخت موفق، فایل‌ها بلافاصله به کتابخانه شما اضافه می‌شوند.' : ($isDeposit ? 'این پرداخت به همین سفارش ثبت می‌شود و در تسویه نهایی همان خدمت منظور خواهد شد.' : 'مبلغ نهایی بر مبنای نسخه نهایی سند محاسبه می‌شود و پرداخت‌های قبلی مرتبط با همین سفارش در تسویه لحاظ می‌شوند.') }}</p></div>
    </section>

    <section class="checkout-card pay-card" aria-labelledby="checkout-payment-title">
      <div class="card-title"><span><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span><div><h2 id="checkout-payment-title">روش پرداخت</h2><small>روش مناسب خود را انتخاب کنید</small></div></div>
      <div class="wallet-balance" aria-label="موجودی کیف پول">
        <span>موجودی فعلی کیف پول</span>
        <strong>{{ number_format($wallet->balance_rials) }} <small>ریال</small></strong>
      </div>

      <form method="post" action="{{ route('checkout.wallet',$order) }}">
        @csrf
        <label class="terms-check"><input type="checkbox" name="accept_terms" value="1" required><span>قوانین پرداخت و شرایط استفاده را مطالعه کرده‌ام و می‌پذیرم.</span></label>
        <button class="pay-button" type="submit" {{ $wallet->balance_rials < $order->total_rials ? 'disabled aria-describedby=wallet-insufficient' : '' }}><i class="fa-solid fa-wallet" aria-hidden="true"></i> پرداخت با کیف پول · {{ number_format($order->total_rials) }} ریال</button>
      </form>
      @if($wallet->balance_rials < $order->total_rials)
        <div class="insufficient" id="wallet-insufficient" role="status">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <div><strong>موجودی کیف پول کافی نیست</strong><span>برای تکمیل این پرداخت، {{ number_format($walletShortfall) }} ریال دیگر نیاز دارید.</span><a href="{{ route('wallet') }}">افزایش موجودی کیف پول <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></div>
        </div>
      @endif

      @if($isStore && $zarinpalOn && (int)$order->total_rials > 0)
        <div class="checkout-method-divider"><span>یا پرداخت با درگاه</span></div>
        <form method="post" action="{{ route('checkout.zarinpal',$order) }}">
          @csrf
          <label class="terms-check"><input type="checkbox" name="accept_terms" value="1" required><span>قوانین پرداخت را می‌پذیرم و به درگاه زرین‌پال منتقل می‌شوم.</span></label>
          <button class="pay-button pay-button-gateway" type="submit"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> پرداخت مستقیم زرین‌پال · {{ number_format($order->total_rials) }} ریال</button>
        </form>
      @endif

      <div class="payment-security" aria-label="ویژگی‌های امنیت پرداخت"><span><i class="fa-solid fa-lock" aria-hidden="true"></i> اتصال امن</span><span><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> ثبت قابل پیگیری</span><span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> محاسبه سمت سرور</span></div>
    </section>
  </div>
</div>
@endsection
