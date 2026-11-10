<section class="store-panel product-file-first-step" aria-labelledby="file-first-title">
  <div class="product-editor-heading">
    <div><span>گام اول · ورود فایل</span><h2 id="file-first-title">ابتدا فایل محصول را انتخاب کنید</h2></div>
    <span class="product-editor-step">پیش از تکمیل مشخصات</span>
  </div>
  <p>در این مرحله هنوز نیازی به وارد کردن عنوان، قیمت یا توضیحات نیست. ابتدا فایل یا پوشه را انتخاب کنید؛ گزارش تعداد فایل‌ها، حجم و بررسی ساختاری نمایش داده می‌شود. پس از تأیید و ذخیره خصوصی، بررسی محتوایی ناظر هوشمند در پس‌زمینه آغاز می‌شود و شما به فرم تکمیل مشخصات محصول منتقل خواهید شد.</p>
  <ul class="product-file-first-points">
    <li>انتخاب یک فایل یا یک پوشه و مشاهده فهرست کامل فایل‌ها</li>
    <li>بررسی نوع، حجم، ساختار، آرشیوها و هش پیش از ذخیره‌سازی</li>
    <li>اسکن تکمیلی آنتی‌ویروس فقط با انتخاب مدیر یا کاربر دارای مجوز</li>
    <li>ذخیره خصوصی و شروع خودکار بررسی هوشمند؛ بدون انتشار عمومی</li>
  </ul>
  <section class="product-ingestion" data-product-ingestion
    data-product-id=""
    data-preflight-url="{{ route('admin.store.intake.preflight') }}"
    data-product-preflight-url-template="{{ route('admin.store.files.preflight', '__PRODUCT__') }}"
    data-confirm-url-template="{{ route('admin.store.intake.confirm', ['__PRODUCT__', '__INGESTION__']) }}"
    data-cancel-url-template="{{ route('admin.store.intake.cancel', ['__PRODUCT__', '__INGESTION__']) }}"
    data-status-url-template="{{ route('admin.store.files.processing', ['__PRODUCT__', '__FILE__']) }}">
    <input type="hidden" data-ingestion-csrf value="{{ csrf_token() }}">
    <input type="file" data-ingestion-input multiple hidden accept=".pdf,.zip,.tar,.tar.gz,.tar.bz2,.jpg,.jpeg,.png,.webp,.gif,.docx,.xlsx,.pptx,.txt,.csv,.md,.mp3,.wav,.ogg,.flac,.m4a,.mp4,.webm,.mov">
    <input type="file" data-ingestion-folder multiple webkitdirectory directory hidden>
    <div class="product-ingestion-dropzone" data-ingestion-pick tabindex="0" role="button" aria-label="انتخاب یا رها کردن فایل محصول">
      <span class="product-ingestion-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
      <strong>فایل را اینجا رها کنید یا برای انتخاب کلیک کنید</strong>
      <span>می‌توانید فایل یا پوشه را روی همین کادر بکشید و رها کنید.</span>
      <small>PDF، Word، Excel، PowerPoint، تصویر، صوت، ویدئو، ZIP، TAR و متن · حداکثر ۵۰ مگابایت برای هر فایل</small>
    </div>
    <div class="product-ingestion-folder-action"><button type="button" class="store-outline-btn" data-ingestion-folder-pick>انتخاب یک پوشه</button><small>فایل‌های پوشه به‌صورت فهرست قابل بازبینی اضافه می‌شوند.</small></div>
    <label class="product-ingestion-primary"><input type="checkbox" data-ingestion-primary checked> اولین فایل، فایل اصلی محصول باشد</label>
    <div class="product-ingestion-queue" data-ingestion-queue aria-live="polite"></div>
    <div class="product-ingestion-global-drop" data-ingestion-global hidden><span><i class="fa-solid fa-cloud-arrow-down"></i></span><b>فایل را رها کنید تا پنجره بازبینی باز شود</b><small>هنوز چیزی منتشر نمی‌شود.</small></div>
    <div class="product-ingestion-modal" data-ingestion-modal hidden role="dialog" aria-modal="true" aria-labelledby="ingestion-modal-title">
      <div class="product-ingestion-dialog">
        <div class="product-ingestion-modal-head"><span class="product-ingestion-icon"><i class="fa-solid fa-shield-halved"></i></span><div><small>بررسی داخلی فراست</small><h3 id="ingestion-modal-title">گزارش بررسی فایل</h3><p data-ingestion-filename></p></div><button type="button" data-ingestion-close aria-label="بستن پنجره">×</button></div>
        <div class="product-ingestion-selected" data-ingestion-selected aria-live="polite"></div>
        <div class="product-ingestion-antivirus">
          @if(app(\App\Services\AuthorizationService::class)->allows(auth()->user(), 'store.security_scan'))
            <label><input type="checkbox" data-ingestion-antivirus> اسکن تکمیلی آنتی‌ویروس (اختیاری)</label>
            <small>پیش‌فرض خاموش است. با فعال‌سازی، فایل در قرنطینه خصوصی با موتور ClamAV بررسی می‌شود؛ این گزینه جایگزین بررسی ساختاری داخلی نیست.</small>
          @else
            <small>بررسی ساختاری داخلی فعال است. اسکن آنتی‌ویروس تکمیلی فقط برای حساب‌های دارای مجوز در دسترس است.</small>
          @endif
        </div>
        <div class="product-ingestion-timeline" data-ingestion-timeline>
          <div data-step="received" class="is-done"><i>✓</i><span>دریافت فایل</span></div>
          <div data-step="upload"><i>2</i><span>انتقال امن به قرنطینه</span></div>
          <div data-step="inspect"><i>3</i><span>بررسی ساختار و امنیت</span></div>
          <div data-step="review"><i>4</i><span>تأیید گزارش توسط شما</span></div>
          <div data-step="store"><i>5</i><span>ذخیره‌سازی خصوصی</span></div>
          <div data-step="study"><i>6</i><span>مطالعه و تحلیل محتوا</span></div>
          <div data-step="sample"><i>7</i><span>ساخت فایل نمونه</span></div>
          <div data-step="audio"><i>8</i><span>ساخت نسخه صوتی همگام</span></div>
        </div>
        <div class="product-ingestion-progress" data-ingestion-progress hidden><div><span data-ingestion-progress-bar></span></div><small data-ingestion-progress-label>آماده‌سازی فایل...</small></div>
        <div class="product-ingestion-report" data-ingestion-report><p>در حال بررسی فایل در محیط سایت...</p></div>
        <p class="product-ingestion-security-note">بررسی ساختاری داخلی فایل را اجرا نمی‌کند. اسکن آنتی‌ویروس فقط با انتخاب صریح حساب مجاز و در صورت پیکربندی ClamAV انجام می‌شود؛ هیچ‌یک تضمین کشف همه تهدیدهای ناشناخته نیست.</p>
        <div class="product-ingestion-actions"><button type="button" class="store-outline-btn" data-ingestion-cancel>لغو انتخاب</button><button type="button" class="store-primary-btn" data-ingestion-confirm disabled>بررسی امن فایل</button></div>
      </div>
    </div>
  </section>
  <small class="product-help">پس از ذخیره موفق، به صفحه مشخصات محصول منتقل می‌شوید. تا زمانی که ثبت نهایی را انجام ندهید و بررسی‌های لازم تکمیل نشود، محصول در سایت منتشر نخواهد شد.</small>
</section>
