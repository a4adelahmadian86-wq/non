@extends('layouts.app')
@section('content')
@php($isDeposit = $order->isDeposit())
<div class="finance-page" dir="rtl">
  <div class="finance-head">
    <div>
      <span class="eyebrow">پرداخت امن فراست</span>
      <h1>{{ $isDeposit ? 'تأیید مبلغ شروع سفارش' : 'تأیید و پرداخت سفارش' }}</h1>
      <p>{{ $isDeposit ? 'مبلغ لازم برای شروع این سفارش در ادامه نمایش داده شده است.' : 'قیمت پیش از پرداخت دوباره از متن نهایی محاسبه می‌شود؛ تغییرات بعد از پرداخت نیازمند محاسبه مجدد است.' }}</p>
    </div>
    <a class="finance-back" href="/wallet"><i class="fa-solid fa-wallet"></i> کیف پول</a>
  </div>

  @if($errors->any())
    <div class="finance-alert danger"><i class="fa-solid fa-circle-exclamation"></i>{{ $errors->first() }}</div>
  @endif

  <div class="checkout-grid">
    <section class="checkout-card">
      <div class="card-title"><span><i class="fa-solid fa-receipt"></i></span><div><h2>{{ $isDeposit ? 'شروع سفارش' : 'خلاصه سفارش' }}</h2><small>سفارش شماره {{ $order->id }}</small></div></div>
      <div class="invoice-lines">
        @if($isDeposit)
          <div class="total"><span>مبلغ قابل پرداخت برای شروع</span><strong>{{ number_format($order->total_rials) }} ریال</strong></div>
        @else
          <div><span>هزینه خدمات</span><b>{{ number_format($order->subtotal_rials) }} ریال</b></div>
          @if($order->discount_rials > 0)<div><span>اعتبار هدیه</span><b class="discount">{{ number_format($order->discount_rials) }}- ریال</b></div>@endif
          @if($order->tax_rials > 0)<div><span>مالیات و عوارض</span><b>{{ number_format($order->tax_rials) }} ریال</b></div>@endif
          @if((int) data_get($order->pricing_snapshot, 'deposit_credit_rials', 0) > 0)<div><span>مبلغ پرداخت‌شده پیشین</span><b class="discount">{{ number_format((int) data_get($order->pricing_snapshot, 'deposit_credit_rials', 0)) }}- ریال</b></div>@endif
          <div class="total"><span>مبلغ قابل پرداخت</span><strong>{{ number_format($order->total_rials) }} ریال</strong></div>
        @endif
      </div>
      <div class="legal-box"><i class="fa-solid fa-shield-halved"></i><p>{{ $isDeposit ? 'این پرداخت به همین سفارش ثبت می‌شود و در تسویه نهایی همان خدمت منظور خواهد شد.' : 'مبلغ نهایی بر مبنای نسخه نهایی سند محاسبه می‌شود و پرداخت‌های قبلی مرتبط با همین سفارش در تسویه لحاظ می‌شوند.' }}</p></div>
    </section>

    <section class="checkout-card pay-card">
      <div class="card-title"><span><i class="fa-solid fa-credit-card"></i></span><div><h2>روش پرداخت</h2><small>پرداخت از موجودی کیف پول</small></div></div>
      <div class="wallet-balance"><span>موجودی فعلی</span><strong>{{ number_format($wallet->balance_rials) }} <small>ریال</small></strong></div>
      <form method="post" action="{{ route('checkout.wallet',$order) }}">
        @csrf
        <label class="terms-check"><input type="checkbox" name="accept_terms" value="1" required><span>قوانین پرداخت و شرایط استفاده را مطالعه کرده‌ام و می‌پذیرم.</span></label>
        <button class="pay-button" type="submit" {{ $wallet->balance_rials < $order->total_rials ? 'disabled' : '' }}><i class="fa-solid fa-lock"></i> پرداخت {{ number_format($order->total_rials) }} ریال</button>
      </form>
      @if($wallet->balance_rials < $order->total_rials)<div class="insufficient"><i class="fa-solid fa-circle-info"></i><span>موجودی کیف پول برای این پرداخت کافی نیست.</span></div>@endif
      <div class="payment-security"><span><i class="fa-solid fa-lock"></i> اتصال امن</span><span><i class="fa-solid fa-file-invoice"></i> ثبت قابل پیگیری</span><span><i class="fa-solid fa-shield-halved"></i> محاسبه سمت سرور</span></div>
    </section>
  </div>
</div>
@endsection
