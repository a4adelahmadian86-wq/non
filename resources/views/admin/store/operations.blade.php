@extends('layouts.admin')

@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div>
        <span class="eyebrow">عملیات فروشگاه</span>
        <h1>مرکز عملیات محصولات و پردازش</h1>
        <p>سلامت صف، فضای خصوصی و سرویس‌های پردازش را از یک نقطه بررسی و خطاهای قابل بازیابی را با کنترل انسانی مدیریت کنید.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="store-outline-btn" href="{{ route('admin.store.index') }}">بازگشت به محصولات</a>
      </div>
    </div>

    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="store-alert error" role="alert">{{ $errors->first() }}</div>@endif

    <section class="ops-panel" aria-label="سلامت زیرساخت">
      <h2>اتصال اجزای زیرساخت</h2>
      <div class="ops-grid">
        <div><span>پایگاه داده</span><b>{{ $databaseConnection }}</b></div>
        <div><span>درایور صف</span><b>{{ $queueDriver }}</b></div>
        <div><span>اجرای worker زمان‌بندی‌شده</span><b>{{ $scheduledWorker ? 'فعال' : 'غیرفعال؛ worker مستقل لازم است' }}</b></div>
        <div><span>فضای ذخیره‌سازی خصوصی</span><b class="{{ $privateStorageWritable ? 'is-ok' : 'is-bad' }}">{{ $privateStorageWritable ? 'قابل نوشتن' : 'نیازمند بررسی' }}</b></div>
        <div><span>ناظر محصول</span><b class="{{ $supervisorConfigured ? 'is-ok' : 'is-bad' }}">{{ $supervisorConfigured ? 'تنظیمات موجود' : 'تنظیم نشده' }}</b></div>
        <div><span>سرویس صوت اسناد</span><b class="{{ $audioConfigured ? 'is-ok' : 'is-bad' }}">{{ $audioConfigured ? 'تنظیمات موجود' : 'تنظیم نشده' }}</b></div>
        <div><span>کارهای صف</span><b>{{ $queueStats['pending'] === null ? 'ناموجود' : number_format($queueStats['pending']) }}</b></div>
        <div><span>کارهای ناموفق Laravel</span><b>{{ $queueStats['failed'] === null ? 'ناموجود' : number_format($queueStats['failed']) }}</b></div>
      </div>
      <p class="ops-note">«تنظیمات موجود» فقط وجود پیکربندی را نشان می‌دهد و به معنی پاسخ‌گویی لحظه‌ای سرویس خارجی نیست. هیچ کلید یا مقدار محرمانه‌ای در این صفحه نمایش داده نمی‌شود.</p>
    </section>

    <section class="ops-panel">
      <h2>وضعیت اسناد محصول</h2>
      <div class="ops-grid ops-grid-status">
        @foreach($documentStats as $status => $count)
          <div><span>{{ ['pending'=>'در صف','processing'=>'در حال پردازش','completed'=>'تحلیل کامل','extracted'=>'استخراج‌شده','partial'=>'ناقص','failed'=>'ناموفق','stale'=>'نسخه منسوخ','awaiting_provider'=>'در انتظار سرویس'][$status] }}</span><b>{{ number_format($count) }}</b></div>
        @endforeach
      </div>
    </section>

    <section class="ops-panel">
      <h2>اسناد نیازمند رسیدگی</h2>
      <p class="ops-note">تلاش مجدد فقط برای اسناد ناموفق، منسوخ یا منتظر سرویس در دسترس است؛ فایل باید فعال، اسکن‌شده، در فضای خصوصی و دارای همان SHA-256 ثبت‌شده باشد. سند در حال پردازش یا در صف دوباره dispatch نمی‌شود.</p>
      @forelse($documents as $document)
        <article class="ops-document">
          <div class="ops-document-main">
            <strong>{{ $document->product?->title ?? 'محصول نامشخص' }}</strong>
            <span>سند #{{ $document->id }} · {{ $document->file?->original_name ?? 'فایل نامشخص' }}</span>
            <small>وضعیت: {{ ['failed'=>'ناموفق','stale'=>'نسخه منسوخ','awaiting_provider'=>'در انتظار سرویس'][$document->processing_status] ?? $document->processing_status }} · کد: {{ $document->failure_code ?: 'ثبت نشده' }}</small>
          </div>
          <div class="ops-document-actions">
            @if($document->product)
              <a href="{{ route('admin.store.edit', $document->product) }}">مشاهده محصول</a>
            @endif
            @if($supervisorConfigured)
              <form method="post" action="{{ route('admin.store.operations.documents.retry', $document) }}" onsubmit="return confirm('سلامت و یکسانی نسخه فایل دوباره بررسی و سند وارد صف شود؟')">
                @csrf
                <button type="submit" class="store-outline-btn">بررسی و تلاش مجدد</button>
              </form>
            @else
              <span class="ops-note">تا اتصال ناظر محصول برقرار نشود، تلاش مجدد غیرفعال است.</span>
            @endif
          </div>
        </article>
      @empty
        <p class="ops-note">در حال حاضر سندی با وضعیت ناموفق، منسوخ یا منتظر سرویس وجود ندارد.</p>
      @endforelse
    </section>
  </div>
</section>

<style>
.ops-panel{display:grid;gap:14px;padding:18px;margin:0 0 16px;border:1px solid var(--st-line,#e2e8f0);border-radius:14px;background:#fff}
.ops-panel h2{margin:0;font-size:1rem;color:var(--st-ink,#172033)}
.ops-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.ops-grid>div{display:grid;gap:6px;padding:12px;border-radius:10px;background:#f7f9fc;min-width:0}
.ops-grid span{font-size:.72rem;color:var(--st-muted,#64748b)}
.ops-grid b{font-size:.84rem;color:var(--st-ink,#172033);overflow-wrap:anywhere}
.ops-grid .is-ok{color:#047857}.ops-grid .is-bad{color:#b45309}
.ops-grid-status{grid-template-columns:repeat(4,minmax(0,1fr))}
.ops-note{margin:0;font-size:.76rem;line-height:1.9;color:var(--st-muted,#64748b)}
.ops-document{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px;border:1px solid var(--st-line,#e2e8f0);border-radius:10px}
.ops-document-main{display:grid;gap:4px;min-width:0}.ops-document-main strong{font-size:.82rem}.ops-document-main span,.ops-document-main small{font-size:.7rem;color:var(--st-muted,#64748b);overflow-wrap:anywhere}
.ops-document-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ops-document-actions a{font-size:.74rem;color:var(--st-blue,#2563eb);font-weight:700}.ops-document-actions form{margin:0}
@media(max-width:760px){.ops-grid,.ops-grid-status{grid-template-columns:repeat(2,minmax(0,1fr))}.ops-document-actions{width:100%}.ops-document-actions form,.ops-document-actions button{width:100%}}
</style>
@endsection
