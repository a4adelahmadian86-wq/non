@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div><span class="eyebrow">سبد خرید</span><h1>مرور سفارش و تسویه</h1><p>مبالغ در سرور دوباره محاسبه می‌شوند.</p></div>
      <a class="store-outline-btn" href="{{ route('store') }}"><i class="fa-solid fa-arrow-right"></i> ادامه خرید</a>
    </div>

    @if(session('status'))
      <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif
    @if($errors->any())
      <div class="store-alert error"><i class="fa-solid fa-triangle-exclamation"></i>{{ $errors->first() }}</div>
    @endif
    <p class="store-cart-feedback" id="storeCartActionFeedback" role="status" aria-live="polite" aria-atomic="true" hidden></p>

    @if(empty($cart['items']))
      <div class="store-empty-state">
        <i class="fa-solid fa-cart-shopping"></i>
        <h2>سبد خرید خالی است</h2>
        <p>از فروشگاه محصول اضافه کنید.</p>
        <a class="store-primary-btn" href="{{ route('store') }}">مشاهده فروشگاه</a>
      </div>
    @else
      <div class="store-cart-grid">
        <div class="store-cart-list">
          @foreach($cart['items'] as $item)
            <div class="store-cart-row" data-product="{{ $item['product_id'] }}">
              <div>
                <strong>{{ $item['title'] }}</strong>
                <small>{{ number_format($item['unit_price_rials']/10) }} تومان برای هر عدد</small>
              </div>
              <div class="store-qty">
                <button type="button" data-cart-action="minus">−</button>
                <b>{{ $item['quantity'] }}</b>
                <button type="button" data-cart-action="plus">+</button>
                <button type="button" data-cart-action="remove" title="حذف">حذف</button>
              </div>
              <strong>{{ number_format($item['line_total_rials']/10) }} تومان</strong>
            </div>
          @endforeach
        </div>

        <aside class="store-cart-summary">
          <strong style="display:block;margin-bottom:10px">خلاصه سفارش</strong>
          <div class="store-summary-line"><span>جمع کالاها</span><span>{{ number_format($cart['subtotal_rials']/10) }} تومان</span></div>
          <div class="store-summary-line"><span>تخفیف</span><span>{{ number_format(($cart['discount_rials']??0)/10) }} تومان</span></div>
          <div class="store-summary-line total"><span>قابل پرداخت</span><strong>{{ number_format($cart['total_rials']/10) }} تومان</strong></div>

          <form method="post" action="{{ route('cart.coupon') }}" class="store-coupon">
            @csrf
            <input name="code" value="{{ $cart['coupon_code'] ?? '' }}" placeholder="کد تخفیف" @if(!empty($cart['coupon_code'])) readonly @endif>
            @if(!empty($cart['coupon_code']))
              <button type="submit" formaction="{{ route('cart.coupon.clear') }}" formmethod="post">حذف</button>
            @else
              <button type="submit">اعمال</button>
            @endif
          </form>

          <form method="post" action="{{ route('cart.checkout') }}" class="store-checkout-form">
            @csrf
            <label><input type="checkbox" name="accept_terms" value="1" required> قوانین خرید دیجیتال را می‌پذیرم.</label>
            <button type="submit">{{ ($cart['total_rials']??0)===0 ? 'دریافت رایگان' : 'ادامه به پرداخت' }}</button>
          </form>
        </aside>
      </div>
    @endif

    @if(isset($recent) && $recent->count())
      <div class="store-recent">
        <h3>اخیراً دیده‌شده</h3>
        <div class="store-recent-grid">
          @foreach($recent as $p)
            <a href="{{ route('store.product',$p->slug) }}">{{ $p->title }}</a>
          @endforeach
        </div>
      </div>
    @endif
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929">
<style>
.store-cart-feedback{position:relative;margin:0 0 18px;padding:12px 15px 12px 42px;border:1px solid #f1c4c4;border-radius:13px;background:#fff5f5;color:#a52d36;font-size:.8rem;font-weight:700;line-height:1.9}
.store-cart-feedback[hidden]{display:none}
.store-cart-feedback[data-state="success"]{border-color:#b9e5d1;background:#f0fbf5;color:#176b46}
.store-cart-feedback::after{content:"!";position:absolute;left:14px;top:50%;transform:translateY(-50%);display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#ffe1e1;color:#a52d36;font-size:.78rem;font-weight:900}
.store-cart-feedback[data-state="success"]::after{content:"✓";background:#d7f4e4;color:#176b46}
.store-cart-row[aria-busy="true"]{opacity:.72}
.store-cart-row [data-cart-action]:disabled{cursor:wait}

</style>
@endpush
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const feedback=document.getElementById('storeCartActionFeedback');
  const announce=(message,state='error')=>{
    if(!feedback)return;
    feedback.hidden=false;
    feedback.dataset.state=state;
    feedback.textContent=message;
  };
  async function call(url,body){
    const response=await fetch(url,{
      method:'POST',
      credentials:'same-origin',
      headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},
      body:JSON.stringify(body||{})
    });
    const raw=await response.text();
    let payload={};
    if(raw){try{payload=JSON.parse(raw)}catch(_){payload={}}}
    if(!response.ok){
      throw new Error(payload.message||(response.status===401?'برای ادامه ابتدا وارد حساب خود شوید.':'به‌روزرسانی سبد انجام نشد. لطفاً دوباره تلاش کنید.'));
    }
    return payload;
  }
  document.querySelectorAll('.store-cart-row').forEach(row=>row.addEventListener('click',async event=>{
    const button=event.target.closest('[data-cart-action]');
    if(!button||button.disabled)return;
    const id=row.dataset.product;
    const action=button.dataset.cartAction;
    const controls=[...row.querySelectorAll('[data-cart-action]')];
    controls.forEach(control=>{control.disabled=true});
    row.setAttribute('aria-busy','true');
    const originalLabel=button.textContent;
    button.textContent='…';
    try{
      if(action==='remove')await call('/cart/products/'+encodeURIComponent(id)+'/remove');
      else{
        const current=Number(row.querySelector('.store-qty b')?.textContent||1);
        const quantity=current+(action==='plus'?1:-1);
        await call('/cart/products/'+encodeURIComponent(id)+'/update',{quantity:Math.max(0,quantity)});
      }
      announce('سبد خرید به‌روزرسانی شد.','success');
      window.location.reload();
    }catch(error){
      button.textContent=originalLabel;
      controls.forEach(control=>{control.disabled=false});
      row.removeAttribute('aria-busy');
      announce(error.message||'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.');
    }
  }));
})();
</script>
@endpush
