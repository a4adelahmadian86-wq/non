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

    @if(empty($cart['items']))
      <div class="store-empty-state">
        <i class="fa-solid fa-cart-shopping"></i>
        <h2>سبد خرید خالی است</h2>
        <p>از فروشگاه محصول اضافه کنید.</p>
        <a class="store-primary-btn" href="{{ route('store') }}">مشاهده فروشگاه</a>
      </div>
    @else
      <div class="store-cart-grid">
        <div class="store-cart-feedback" role="status" aria-live="polite" aria-atomic="true" hidden></div>
        <div class="store-cart-list">
          @foreach($cart['items'] as $item)
            <div class="store-cart-row" data-product="{{ $item['product_id'] }}">
              <div>
                <strong>{{ $item['title'] }}</strong>
                <small>{{ number_format($item['unit_price_rials']/10) }} تومان برای هر عدد</small>
              </div>
              <div class="store-qty">
                <button type="button" data-cart-action="minus" aria-label="کاهش تعداد {{ $item['title'] }}">−</button>
                <b aria-live="polite">{{ $item['quantity'] }}</b>
                <button type="button" data-cart-action="plus" aria-label="افزایش تعداد {{ $item['title'] }}">+</button>
                <button type="button" data-cart-action="remove" title="حذف" aria-label="حذف {{ $item['title'] }} از سبد">حذف</button>
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
<link rel="stylesheet" href="/css/store.css?v=20261109cart3">
@endpush
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  async function call(url,body){
    const r=await fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify(body||{})});
    const j=await r.json(); if(!r.ok) throw new Error(j.message||'خطا در سبد'); return j;
  }
  const feedback=document.querySelector('.store-cart-feedback');
  const showFeedback=(message)=>{
    if(!feedback)return;
    feedback.hidden=false;
    feedback.textContent=message;
    feedback.classList.add('is-error');
  };
  document.querySelectorAll('.store-cart-row').forEach(row=>row.addEventListener('click',async e=>{
    const b=e.target.closest('[data-cart-action]'); if(!b||b.disabled) return;
    const id=row.dataset.product; const action=b.dataset.cartAction;
    if(action==='remove'&&!window.confirm('این محصول از سبد خرید حذف شود؟'))return;
    const controls=row.querySelectorAll('button[data-cart-action]');
    controls.forEach(control=>{control.disabled=true;});
    if(feedback){feedback.hidden=true;feedback.textContent='';}
    try{
      if(action==='remove') await call('/cart/products/'+id+'/remove');
      else {
        const current=Number(row.querySelector('.store-qty b').textContent||1);
        const q=current+(action==='plus'?1:-1);
        await call('/cart/products/'+id+'/update',{quantity:Math.max(0,q)});
      }
      location.reload();
    }catch(err){
      controls.forEach(control=>{control.disabled=false;});
      showFeedback(err.message||'به‌روزرسانی سبد خرید انجام نشد؛ دوباره تلاش کنید.');
    }
  }));
})();
</script>
@endpush
