@extends('layouts.app')

@section('content')
<main class="trust-page" id="app-main" dir="rtl">
  <section class="trust-hero" aria-labelledby="trust-page-title">
    <div>
      <span class="trust-hero__eyebrow"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> شفافیت و اعتماد</span>
      <h1 id="trust-page-title">اعتبار و نمادهای اعتماد فراست</h1>
      <p>در این صفحه، نمادها، مجوزها و مسیرهای رسمی استعلام فراست به‌صورت یکجا معرفی می‌شوند. اطلاعات هر نماد فقط بر اساس تصویر و نشانی رسمی ثبت‌شده نمایش داده خواهد شد.</p>
    </div>
    <div class="trust-hero__seal" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></div>
  </section>

  <div class="trust-section-head">
    <div><h2>نمادها و مجوزهای رسمی</h2><p>برای مشاهده جزئیات یا استعلام، از پیوند رسمی هر مورد استفاده کنید.</p></div>
  </div>

  @if($trustBadges->isNotEmpty())
    <section class="trust-badge-grid" aria-label="فهرست نمادها و مجوزهای فراست">
      @foreach($trustBadges as $badge)
        <article class="trust-badge-card">
          <div class="trust-badge-card__image">
            <img src="{{ $badge['image'] }}" alt="{{ $badge['name'] }}" loading="lazy" decoding="async">
          </div>
          <h3>{{ $badge['name'] }}</h3>
          @if(!empty($badge['description']))<p>{{ $badge['description'] }}</p>@endif
          @if(!empty($badge['verify_url']))<a href="{{ $badge['verify_url'] }}" target="_blank" rel="noopener noreferrer">استعلام در مرجع رسمی <i class="fa-solid fa-arrow-up-left-from-square" aria-hidden="true"></i></a>@endif
        </article>
      @endforeach
    </section>
  @else
    <section class="trust-empty">
      <span class="trust-empty__icon"><i class="fa-solid fa-certificate" aria-hidden="true"></i></span>
      <h3>جایگاه نمادهای رسمی فراست</h3>
      <p>هنوز تصویر یا اطلاعات قابل‌استعلامی برای نمادهای رسمی در سامانه ثبت نشده است. برای جلوگیری از نمایش نشان‌های غیرواقعی، این بخش پس از ثبت اطلاعات رسمی تکمیل می‌شود.</p>
    </section>
  @endif

  <section class="trust-principles" aria-label="تعهدهای شفافیت فراست">
    <article class="trust-principle"><i class="fa-solid fa-file-contract" aria-hidden="true"></i><div><strong>شرایط روشن خدمات</strong><span>قوانین استفاده، حریم خصوصی و شرایط بازگشت وجه در دسترس هستند.</span></div></article>
    <article class="trust-principle"><i class="fa-solid fa-receipt" aria-hidden="true"></i><div><strong>اطلاعات شفاف خرید</strong><span>جزئیات محصولات و مسیرهای خرید در صفحات مربوط به هر محصول ارائه می‌شود.</span></div></article>
    <article class="trust-principle"><i class="fa-solid fa-headset" aria-hidden="true"></i><div><strong>مسیر ارتباط و پشتیبانی</strong><span>برای پیگیری پرسش‌ها و درخواست‌ها می‌توانید از مرکز پشتیبانی استفاده کنید.</span></div></article>
  </section>
</main>
@endsection
