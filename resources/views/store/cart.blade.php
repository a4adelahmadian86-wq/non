@extends('layouts.app')

@section('content')
@php
  $items = $cart['items'] ?? [];
  $count = count($items);
  $subtotal = (int) ($cart['subtotal_rials'] ?? $cart['total_rials'] ?? 0);
  $total = (int) ($cart['total_rials'] ?? 0);
  $discount = max(0, $subtotal - $total);
@endphp
<div class="fm-cart" dir="rtl">
  <div class="fm-cart-wrap">
    <div class="fm-cart-head">
      <div>
        <h1>سبد خرید</h1>
        <p class="fm-cart-sub">انتخاب‌هایتان را بررسی کنید و با یک مسیر ساده به پرداخت بروید.</p>
      </div>
      <a class="fm-continue" href="{{ route('store') }}"><i class="fa-solid fa-arrow-right"></i> ادامه خرید</a>
    </div>

    @if($count === 0)
      <div class="fm-cart-empty">
        <i class="fa-solid fa-bag-shopping"></i>
        <strong>سبد خرید شما خالی است.</strong>
        <a href="{{ route('store') }}">رفتن به فروشگاه</a>
      </div>
    @else
      <div class="fm-cart-layout">
        <div class="fm-cart-main">
          <section class="fm-box">
            <div class="fm-box-title">
              <strong>فایل‌های انتخاب‌شده</strong>
              <span>{{ $count }} فایل</span>
            </div>
            @foreach($items as $item)
              <div class="fm-row" data-product="{{ $item['product_id'] }}">
                <div class="fm-row-product">
                  <div class="fm-thumb"><i class="fa-solid fa-file"></i></div>
                  <div>
                    <strong>{{ $item['title'] }}</strong>
                    <small>فایل دیجیتال · دریافت پس از پرداخت</small>
                  </div>
                </div>
                <div class="fm-row-price">{{ number_format(((int)$item['line_total_rials']) / 10) }} تومان</div>
                <div class="fm-row-actions">
                  <button type="button" data-cart-action="minus" title="کاهش">−</button>
                  <b>{{ $item['quantity'] }}</b>
                  <button type="button" data-cart-action="plus" title="افزایش">+</button>
                  <button type="button" data-cart-action="remove" class="fm-remove" title="حذف"><i class="fa-regular fa-trash-can"></i></button>
                </div>
              </div>
            @endforeach
          </section>
        </div>

        <aside class="fm-summary">
          <h2>خلاصه خرید</h2>
          <div class="fm-sum-line"><span>مجموع فایل‌ها</span><strong>{{ number_format($subtotal / 10) }} تومان</strong></div>

          <div class="fm-discount">
            <h3>کد تخفیف</h3>
            <div class="fm-discount-form">
              <input type="text" placeholder="کد تخفیف" disabled aria-label="کد تخفیف">
              <button type="button" disabled>اعمال</button>
            </div>
            <small class="fm-discount-note">اعمال کد تخفیف به‌زودی از پنل فعال می‌شود.</small>
          </div>

          <div class="fm-sum-line"><span>تخفیف</span><strong>-{{ number_format($discount / 10) }} تومان</strong></div>
          <div class="fm-sum-line fm-saving"><span>سود شما از این خرید</span><strong>{{ number_format($discount / 10) }} تومان</strong></div>
          <hr>
          <div class="fm-sum-total"><span>مبلغ نهایی</span><strong>{{ number_format($total / 10) }} تومان</strong></div>

          <form method="post" action="{{ route('cart.checkout') }}" class="fm-checkout-form">
            @csrf
            <label class="fm-terms">
              <input type="checkbox" name="accept_terms" value="1" required>
              قوانین خرید دیجیتال را می‌پذیرم.
            </label>
            <button type="submit" class="fm-checkout-btn">ادامه و تسویه‌حساب</button>
          </form>
          <p class="fm-note">پرداخت امن و دریافت فایل پس از تأیید پرداخت</p>
        </aside>
      </div>
    @endif
  </div>
</div>
@endsection

@push('styles')
<style>
.fm-cart{background:#f7f8fc;min-height:calc(100vh - 80px);padding:36px 16px 70px;color:#20242d}
.fm-cart-wrap{width:min(1100px,100%);margin:0 auto}
.fm-cart-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
.fm-cart-head h1{margin:0;font-size:1.55rem}
.fm-cart-sub{margin:6px 0 0;color:#6b7280;font-size:.85rem}
.fm-continue{display:inline-flex;align-items:center;gap:8px;color:#5b3fd0;text-decoration:none;font-size:.85rem;font-weight:700}
.fm-cart-empty{background:#fff;border:1px solid #eee;border-radius:18px;padding:60px 20px;text-align:center;display:grid;gap:10px;justify-items:center;color:#6b7280}
.fm-cart-empty i{font-size:1.8rem;color:#5b3fd0}
.fm-cart-empty a{color:#5b3fd0;font-weight:700;text-decoration:none}
.fm-cart-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start}
.fm-box,.fm-summary{background:#fff;border:1px solid #eee;border-radius:18px;padding:18px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
.fm-box-title{display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid #f0f0f4;margin-bottom:4px}
.fm-box-title span{font-size:.78rem;color:#777}
.fm-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:14px;align-items:center;padding:14px 0;border-bottom:1px solid #f3f3f6}
.fm-row:last-child{border-bottom:0}
.fm-row-product{display:flex;align-items:center;gap:12px}
.fm-thumb{width:54px;height:54px;border-radius:12px;background:#eeeaff;display:grid;place-items:center;color:#5b3fd0}
.fm-row-product strong{display:block;font-size:.9rem}
.fm-row-product small{display:block;color:#7a8498;font-size:.72rem;margin-top:3px}
.fm-row-price{font-weight:800;white-space:nowrap}
.fm-row-actions{display:flex;align-items:center;gap:6px}
.fm-row-actions button{width:34px;height:34px;border:0;border-radius:10px;background:#f5f3fa;color:#4b3aa8;cursor:pointer}
.fm-row-actions button:hover{background:#e8e1ff}
.fm-row-actions .fm-remove{background:#fff1f1;color:#c0392b}
.fm-summary{position:sticky;top:88px}
.fm-summary h2{margin:0 0 12px;font-size:1.05rem}
.fm-sum-line,.fm-sum-total{display:flex;justify-content:space-between;gap:10px;padding:10px 0;font-size:.88rem}
.fm-saving{color:#14945b;background:#eefbf4;border-radius:10px;padding:10px 12px;margin:6px 0}
.fm-sum-total{font-size:1.05rem;font-weight:900}
.fm-discount{margin:8px 0 4px;padding:12px;border-radius:12px;background:#faf9fd;border:1px solid #f0eef8}
.fm-discount h3{margin:0 0 8px;font-size:.85rem}
.fm-discount-form{display:flex;gap:8px}
.fm-discount-form input{flex:1;min-width:0;border:1px solid #e5e7eb;border-radius:10px;padding:9px 11px;font:inherit}
.fm-discount-form button{border:0;border-radius:10px;background:#1f2937;color:#fff;padding:0 14px;font:inherit;cursor:not-allowed;opacity:.7}
.fm-discount-note{display:block;margin-top:8px;color:#8a93a3;font-size:.7rem}
.fm-checkout-form{margin-top:12px;display:grid;gap:10px}
.fm-terms{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#5f6b7c}
.fm-checkout-btn{border:0;border-radius:12px;background:#5b3fd0;color:#fff;padding:12px 16px;font:inherit;font-weight:800;cursor:pointer}
.fm-checkout-btn:hover{background:#4a31b5}
.fm-note{margin:10px 0 0;text-align:center;color:#8a93a3;font-size:.72rem}
@media(max-width:900px){.fm-cart-layout{grid-template-columns:1fr}.fm-summary{position:static}.fm-row{grid-template-columns:1fr}}
</style>
@endpush

@push('scripts')
<script>
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  async function call(url, body) {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'},
      body: JSON.stringify(body || {})
    });
    const j = await r.json();
    if (!r.ok) throw new Error(j.message || 'خطا در سبد خرید');
    return j;
  }
  document.querySelectorAll('.fm-row').forEach(row => {
    row.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-cart-action]');
      if (!b) return;
      const id = row.dataset.product;
      const action = b.dataset.cartAction;
      try {
        if (action === 'remove') await call('/cart/products/' + id + '/remove');
        else {
          const current = Number(row.querySelector('b')?.textContent || 1);
          const q = current + (action === 'plus' ? 1 : -1);
          await call('/cart/products/' + id + '/update', {quantity: Math.max(0, q)});
        }
        location.reload();
      } catch (err) {
        alert(err.message);
      }
    });
  });
})();
</script>
@endpush
