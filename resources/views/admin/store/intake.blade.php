@extends('layouts.admin')

@section('admin-content')
<section class="store-page" dir="rtl" data-product-intake
    data-start-url="{{ route('admin.store.intakes.start') }}"
    data-progress-url-template="{{ route('admin.store.intakes.progress', '__SESSION__') }}"
    data-preflight-url-template="{{ route('admin.store.intakes.files.preflight', ['session' => '__SESSION__']) }}"
    data-confirm-url-template="{{ route('admin.store.intakes.files.confirm', ['session' => '__SESSION__', 'ingestionId' => '__INGESTION__']) }}"
    data-cancel-url-template="{{ route('admin.store.intakes.files.cancel', ['session' => '__SESSION__', 'ingestionId' => '__INGESTION__']) }}"
    data-primary-url-template="{{ route('admin.store.intakes.files.primary', ['session' => '__SESSION__', 'ingestionId' => '__INGESTION__']) }}"
    data-details-url-template="{{ route('admin.store.intakes.details', '__SESSION__') }}">
    <div class="store-container">
        <header class="store-section-head">
            <div>
                <span class="eyebrow">مرکز مدیریت محصولات</span>
                <h1>افزودن محصول جدید</h1>
                <p>ابتدا نوع محصول را انتخاب کنید؛ سپس فایل‌ها در قرنطینه بررسی می‌شوند. مشخصات محصول بعد از پایان بررسی ثبت خواهد شد.</p>
            </div>
            <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به فهرست محصولات</a>
        </header>

        <div class="product-workflow" aria-label="مراحل ثبت محصول">
            <a href="#intake-type"><span>۱</span><b>نوع محصول</b><small>انتخاب مسیر</small></a>
            <a href="#intake-files"><span>۲</span><b>فایل‌ها</b><small>بازرسی و ذخیره امن</small></a>
            <a href="#intake-review"><span>۳</span><b>بازبینی ناظر</b><small>بررسی خودکار</small></a>
            <a href="#intake-next"><span>۴</span><b>مشخصات</b><small>ثبت محصول</small></a>
        </div>

        <section class="store-panel" id="intake-type">
            <div class="product-editor-heading">
                <div><span>گام اول</span><h2>نوع محصول را انتخاب کنید</h2></div>
            </div>
            <div class="product-editor-grid">
                <label class="product-field product-field-check" style="align-items:flex-start;gap:12px">
                    <input type="radio" name="product_type" value="digital_file" checked data-intake-type>
                    <span><strong>فایل دیجیتال</strong><small>برای فایل‌های دانلودی، اسناد، کتاب، قالب و محتوای دیجیتال</small></span>
                </label>
                <label class="product-field product-field-check" style="align-items:flex-start;gap:12px">
                    <input type="radio" name="product_type" value="course" data-intake-type>
                    <span><strong>دوره آموزشی</strong><small>برای دوره‌هایی که فایل‌های اولیه آن می‌توانند پیش از ساخت فصل و جلسه ثبت شوند</small></span>
                </label>
            </div>
            <button type="button" class="store-primary-btn" data-intake-start>شروع فرایند امن</button>
            <p class="product-ingestion-info" data-intake-message role="status" aria-live="polite"></p>
        </section>

        <section class="store-panel" id="intake-files" data-intake-upload hidden>
            <div class="product-editor-heading">
                <div><span>گام دوم</span><h2>انتخاب فایل یا پوشه</h2></div>
                <span class="product-editor-step" data-intake-type-label></span>
            </div>
            <p>فایل‌ها ابتدا فقط در قرنطینه خصوصی ذخیره و بررسی ساختاری می‌شوند. تا زمانی که گزارش هر فایل را تأیید نکنید، به فضای نهایی منتقل نخواهد شد.</p>
            <div class="product-editor-grid">
                <label class="product-field product-field-wide">
                    انتخاب چند فایل
                    <input type="file" multiple data-intake-file-picker accept=".pdf,.zip,.tar,.gz,.bz2,.docx,.xlsx,.pptx,.txt,.csv,.md,.jpg,.jpeg,.png,.webp,.gif,.mp3,.wav,.ogg,.flac,.m4a,.mp4,.webm,.mov">
                </label>
                <label class="product-field product-field-wide">
                    انتخاب پوشه
                    <input type="file" multiple webkitdirectory directory data-intake-folder-picker>
                    <small>ساختار پوشه برای نمایش و گزارش‌گیری حفظ می‌شود؛ مسیر محلی دستگاه در سرور ذخیره نمی‌شود.</small>
                </label>
            </div>
            <div class="product-ingestion-report-grid" data-intake-stats>
                <div><small>تعداد فایل</small><b data-stat-files>۰</b></div>
                <div><small>تعداد پوشه</small><b data-stat-folders>۰</b></div>
                <div><small>فایل آرشیو</small><b data-stat-archives>۰</b></div>
                <div><small>حجم کل</small><b data-stat-size>۰ بایت</b></div>
            </div>
            <div class="product-ingestion-queue" data-intake-file-list></div>
            <div class="product-ingestion-actions">
                <button type="button" class="store-primary-btn" data-intake-process disabled>بررسی و انتقال فایل‌ها</button>
                <button type="button" class="store-outline-btn" data-intake-clear>پاک‌کردن انتخاب‌ها</button>
            </div>
        </section>

        <section class="store-panel" id="intake-review" data-intake-review hidden>
            <div class="product-editor-heading">
                <div><span>گام سوم</span><h2>وضعیت بررسی فایل‌ها</h2></div>
                <span class="product-editor-step" data-intake-session-status>در انتظار</span>
            </div>
            <div class="product-ingestion-report-grid">
                <div><small>فایل‌های ذخیره‌شده</small><b data-progress-uploaded>۰</b></div>
                <div><small>بررسی تکمیل‌شده</small><b data-progress-reviewed>۰</b></div>
                <div><small>در انتظار بررسی</small><b data-progress-pending>۰</b></div>
                <div><small>نیازمند رسیدگی</small><b data-progress-attention>۰</b></div>
            </div>
            <div class="product-ingestion-queue" data-intake-review-list></div>
            <div class="store-alert error" data-intake-attention hidden>برخی فایل‌ها هنوز بررسی کامل و قابل تأیید ندارند. تا رفع وضعیت آن‌ها امکان ثبت مشخصات و ارسال محصول وجود ندارد.</div>
        </section>

        <section class="store-panel" id="intake-next" data-intake-next hidden>
            <div class="product-editor-heading">
                <div><span>گام چهارم</span><h2>فایل‌ها آماده‌اند</h2></div>
                <span class="product-editor-step">بازبینی کامل شد</span>
            </div>
            <p>همه فایل‌های این فرایند از بررسی ناظر عبور کرده‌اند. اکنون می‌توانید مشخصات واقعی محصول را وارد کنید؛ هنوز هیچ محصولی در کاتالوگ ساخته نشده است.</p>
            <a class="store-primary-btn" href="#" data-intake-details>ادامه و تکمیل مشخصات محصول</a>
        </section>
    </div>
</section>

<div class="product-ai-content-modal" data-intake-file-modal hidden>
    <div class="product-ai-content-dialog" role="dialog" aria-modal="true" aria-labelledby="intake-file-title">
        <div class="product-ai-content-dialog-head">
            <div><span>گزارش بازرسی ساختاری</span><h3 id="intake-file-title" data-intake-file-title>فایل انتخاب‌شده</h3></div>
        </div>
        <div data-intake-file-report></div>
        <div class="product-ingestion-actions">
            <button type="button" class="store-primary-btn" data-intake-file-confirm>تأیید و ذخیره در فضای مدیریت‌شده</button>
            <button type="button" class="store-outline-btn" data-intake-file-reject>رد این فایل</button>
        </div>
    </div>
</div>

<style>
[data-product-intake] .product-ingestion-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:18px 0}
[data-product-intake] .product-ingestion-report-grid>div{padding:14px;border:1px solid var(--border-color,#dbe2ea);border-radius:12px;background:var(--surface-soft,#f8fafc)}
[data-product-intake] .product-ingestion-report-grid small{display:block;color:var(--muted-color,#64748b);margin-bottom:6px}
[data-product-intake] .product-ingestion-report-grid b{display:block;overflow-wrap:anywhere}
[data-product-intake] .product-ingestion-queue{display:grid;gap:8px;margin:14px 0}
[data-product-intake] .product-ingestion-queue>div{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--border-color,#dbe2ea);border-radius:10px}
[data-product-intake] .product-ingestion-queue small{display:block;color:var(--muted-color,#64748b)}
[data-product-intake] .product-ingestion-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
[data-intake-file-modal] [data-intake-file-report]{max-height:55vh;overflow:auto;padding:12px 0}
[data-intake-file-modal] .product-ingestion-warning{padding:10px;margin:8px 0;border-radius:8px;background:#fff4e5;color:#854d0e}
[data-intake-file-modal] .product-ingestion-info{padding:10px;margin:8px 0;border-radius:8px;background:#eff6ff;color:#1e40af}
@media(max-width:640px){[data-product-intake] .product-ingestion-queue>div{align-items:flex-start;flex-direction:column}}
</style>
<script src="{{ asset('js/product-intake-session.js') }}?v=20261111intake1" defer></script>
@endsection
