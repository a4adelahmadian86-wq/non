@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div><span class="eyebrow">ورود امن محصول</span><h1>ابتدا نوع محصول را انتخاب کنید</h1><p>در این مرحله هنوز محصولی در کاتالوگ ساخته نمی‌شود. ابتدا فایل‌ها بازرسی و به فضای ذخیره‌سازی منتقل می‌شوند.</p></div>
      <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به محصولات</a>
    </div>
    <form method="post" action="{{ route('admin.store.intake.store') }}" class="store-panel" style="padding:24px;max-width:860px">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
        <label class="store-panel" style="padding:18px;cursor:pointer">
          <input type="radio" name="product_type" value="digital_file" required checked>
          <strong style="display:block;margin-top:10px">فایل دیجیتال</strong>
          <span>کتاب، سند، فایل صوتی، ویدئو، بسته ZIP و سایر فایل‌های قابل دانلود</span>
        </label>
        <label class="store-panel" style="padding:18px;cursor:pointer">
          <input type="radio" name="product_type" value="course" required>
          <strong style="display:block;margin-top:10px">دوره آموزشی</strong>
          <span>محتوای آموزشی و منابعی که بعداً به فصل‌ها و جلسه‌های دوره متصل می‌شوند</span>
        </label>
      </div>
      <div style="margin-top:20px;padding:14px;border:1px solid var(--border-color,#ddd);border-radius:12px">
        <strong>بررسی امنیتی الزامی</strong>
        <p>بررسی ساختار، نوع واقعی فایل، اندازه، آرشیو و شاخص‌های خطر برای همه فایل‌ها اجرا می‌شود.</p>
        @if($canRequestAntivirus)
          <label style="display:flex;gap:10px;align-items:center">
            <input type="checkbox" name="antivirus_requested" value="1">
            <span>درخواست اسکن تکمیلی با موتور آنتی‌ویروس محلی ClamAV</span>
          </label>
          <small>در صورت نبودن موتور یا خطای آن، فایل تأیید نمی‌شود.</small>
        @else
          <small>اسکن تکمیلی آنتی‌ویروس برای این حساب مجاز نیست؛ بررسی ساختاری همچنان اجباری است.</small>
        @endif
      </div>
      <button class="store-primary-btn" type="submit" style="margin-top:20px">ادامه به انتخاب فایل‌ها</button>
    </form>
  </div>
</section>
@endsection
