<section class="store-panel product-type-choice" aria-labelledby="product-type-choice-title">
  <div class="product-type-choice-head">
    <span>مرحله اول · انتخاب نوع محصول</span>
    <h2 id="product-type-choice-title">ابتدا نوع محصول را انتخاب کنید</h2>
    <p>نوع محصول، مسیر ساخت و ابزارهای مرحله بعد را تعیین می‌کند. برای فایل دیجیتال، ابتدا فایل یا پوشه را بازرسی می‌کنید؛ برای دوره آموزشی، ابتدا مشخصات و ساختار دوره را می‌سازید.</p>
  </div>
  <div class="product-type-choice-grid">
    <a class="product-type-choice-card" href="{{ route('admin.store.create', ['type' => 'digital_file']) }}">
      <span class="product-type-choice-number">۰۱</span>
      <strong>محصول فایل دیجیتال</strong>
      <p>کتاب، جزوه، قالب، فایل صوتی، ویدئو، آرشیو یا هر محتوای دانلودی</p>
      <span class="product-type-choice-action">ادامه با انتخاب فایل یا پوشه <i class="fa-solid fa-arrow-left"></i></span>
    </a>
    <a class="product-type-choice-card" href="{{ route('admin.store.create', ['type' => 'course']) }}">
      <span class="product-type-choice-number">۰۲</span>
      <strong>دوره آموزشی</strong>
      <p>دوره‌های چندبخشی با فصل‌ها، جلسات، ویدئوها، منابع و تنظیمات آموزشی</p>
      <span class="product-type-choice-action">ادامه به فرم ساخت دوره <i class="fa-solid fa-arrow-left"></i></span>
    </a>
  </div>
</section>
