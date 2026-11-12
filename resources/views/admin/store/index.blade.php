@extends('layouts.admin')
@section('admin-content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-section-head">
      <div><span class="eyebrow">مدیریت فروشگاه</span><h1>مرکز مدیریت محصولات</h1><p>محصولات دانلودی و دوره‌های آموزشی را از یک پنل مدیریت کنید.</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="store-outline-btn" href="{{ route('admin.index') }}">بازگشت ادمین</a>
        <a class="store-primary-btn" href="{{ route('admin.store.create') }}"><i class="fa-solid fa-plus"></i> محصول جدید</a>
      </div>
    </div>
    @if(session('status'))<div class="store-alert success">{{ session('status') }}</div>@endif
    <div class="product-center-stats">
      <div><span>همه محصولات</span><b>{{ number_format($stats['total']) }}</b></div>
      <div><span>منتشرشده</span><b>{{ number_format($stats['published']) }}</b></div>
      <div><span>در صف انتشار</span><b>{{ number_format($stats['pending_review']) }}</b></div>
      <div><span>پیش‌نویس</span><b>{{ number_format($stats['drafts']) }}</b></div>
      <div><span>بایگانی‌شده</span><b>{{ number_format($stats['archived']) }}</b></div>
      <div><span>دوره آموزشی</span><b>{{ number_format($stats['courses']) }}</b></div>
      <div><span>فایل دانلودی</span><b>{{ number_format($stats['files']) }}</b></div>
    </div>
    <section class="product-pipeline-panel" aria-label="وضعیت زیرساخت پردازش محصولات">
      <div class="product-pipeline-heading">
        <div><strong>وضعیت زیرساخت پردازش محصولات</strong><small>وضعیت واقعی استخراج، دانش محصول و صف پردازش</small></div>
        <span class="{{ $supervisorConfigured ? 'is-configured' : 'is-unconfigured' }}">ناظر محصول: {{ $supervisorConfigured ? 'تنظیمات تکمیل است' : 'نیازمند تنظیم' }}</span>
      </div>
      <div class="product-pipeline-metrics">
        <div><span>در صف پردازش</span><b>{{ number_format($processingStats['pending']) }}</b></div>
        <div><span>در حال پردازش</span><b>{{ number_format($processingStats['processing']) }}</b></div>
        <div><span>تحلیل کامل</span><b>{{ number_format($processingStats['completed']) }}</b></div>
        <div><span>استخراج‌شده</span><b>{{ number_format($processingStats['extracted']) }}</b></div>
        <div><span>نیازمند بررسی</span><b>{{ number_format($attentionCount) }}</b></div>
        <div><span>قرنطینه منتظر تأیید</span><b>{{ number_format($ingestionStats['inspected']) }}</b></div>
      </div>
      <div class="product-pipeline-meta">
        <span>درایور صف: <b>{{ config('queue.default') }}</b></span>
        <span>کارهای صف اسناد: <b>{{ $queueStats['pending'] === null ? 'ناموجود' : number_format($queueStats['pending']) }}</b></span>
        <span>کارهای ناموفق: <b>{{ $queueStats['failed'] === null ? 'ناموجود' : number_format($queueStats['failed']) }}</b></span>
      </div>
      @if($attentionDocuments->isNotEmpty())
        <div class="product-pipeline-attention">
          <strong>مواردی که به رسیدگی نیاز دارند</strong>
          @foreach($attentionDocuments as $document)
            <div class="product-pipeline-attention-row">
              <span><b>{{ $document->product?->title ?? 'محصول نامشخص' }}</b><small>شناسه سند {{ $document->id }} · {{ $document->failure_code ?: 'نیازمند بررسی وضعیت پردازش' }}</small></span>
              <span class="product-pipeline-status">{{ match($document->processing_status) { 'failed' => 'ناموفق', 'stale' => 'نسخه منسوخ', 'awaiting_provider' => 'در انتظار سرویس', 'processing' => 'پردازش طولانی', 'pending' => 'در صف طولانی', default => $document->processing_status } }}</span>
              @if($document->product)<a href="{{ route('admin.store.edit', $document->product) }}">بررسی محصول</a>@endif
            </div>
          @endforeach
        </div>
      @else
        <p class="product-pipeline-empty">در حال حاضر سندی در وضعیت نیازمند رسیدگی یا پردازش طولانی ثبت نشده است.</p>
      @endif
    </section>
    <form method="get" class="product-center-filters" aria-label="فیلتر و مرتب‌سازی محصولات">
      <input name="q" value="{{ $q }}" placeholder="جستجوی عنوان، شناسه یا برچسب محصول" aria-label="جستجوی محصول">
      <select name="type" aria-label="نوع محصول"><option value="">همه نوع‌ها</option><option value="digital_file" @selected($type==='digital_file')>فایل دانلودی</option><option value="course" @selected($type==='course')>دوره آموزشی</option></select>
      <select name="status" aria-label="وضعیت محصول"><option value="">همه وضعیت‌ها</option><option value="draft" @selected($status==='draft')>پیش‌نویس</option><option value="pending_review" @selected($status==='pending_review')>در صف انتشار</option><option value="published" @selected($status==='published')>منتشرشده</option><option value="archived" @selected($status==='archived')>بایگانی</option></select>
      <select name="category_id" aria-label="دسته‌بندی"><option value="">همه دسته‌ها</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($categoryId===$category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (غیرفعال)' }}</option>@endforeach</select>
      <select name="sort" aria-label="مرتب‌سازی"><option value="newest" @selected($sort==='newest')>جدیدترین</option><option value="oldest" @selected($sort==='oldest')>قدیمی‌ترین</option><option value="updated" @selected($sort==='updated')>آخرین ویرایش</option><option value="title_asc" @selected($sort==='title_asc')>عنوان: الف تا ی</option><option value="price_asc" @selected($sort==='price_asc')>ارزان‌ترین</option><option value="price_desc" @selected($sort==='price_desc')>گران‌ترین</option></select>
      <button type="submit" class="store-primary-btn">اعمال</button>
      <a class="store-outline-btn" href="{{ route('admin.store.index') }}">پاک‌کردن</a>
    </form>
    <form id="bulkArchiveForm" method="post" action="{{ route('admin.store.bulk-archive') }}" class="product-bulk-actions" onsubmit="return confirm('محصولات انتخاب‌شده بایگانی شوند؟ محصولات دارای سفارش یا دسترسی مشتری حذف نمی‌شوند و فقط از فروش عمومی خارج می‌شوند.')">
      @csrf
      <label><input type="checkbox" data-product-select-all> انتخاب همه موارد این صفحه</label>
      <span data-product-selection-count>موردی انتخاب نشده است.</span>
      <button type="submit" class="store-outline-btn" data-product-bulk-submit disabled>بایگانی انتخاب‌شده‌ها</button>
    </form>
    <div class="store-cart-list">
      @forelse($products as $p)
        <div class="store-cart-row">
          <label class="product-row-select" aria-label="انتخاب {{ $p->title }}">
            <input type="checkbox" name="product_ids[]" value="{{ $p->id }}" form="bulkArchiveForm" data-product-row-select>
          </label>
          <div class="product-index-identity">
            @php($thumb = $p->cover_path ?: $p->images->where('is_active', true)->first()?->path)
            @if($thumb)<img src="{{ asset($thumb) }}" alt="" loading="lazy">@else<span class="product-index-placeholder"><i class="fa-solid fa-box"></i></span>@endif
            <span><strong>{{ $p->title }}</strong>
              <small>{{ $p->type === 'course' ? 'دوره آموزشی' : ((($p->download_policy ?? 'signed') === 'reader_only') ? 'فایل · فقط مطالعه آنلاین' : 'فایل دانلودی') }} · {{ $p->sku }} · {{ $p->category?->name }} · {{ ['draft'=>'پیش‌نویس','pending_review'=>'در صف انتشار','published'=>'منتشرشده','archived'=>'بایگانی'][$p->status] ?? $p->status }} · {{ number_format($p->price_rials/10) }} تومان · {{ $p->files->where('purpose','product_download')->count() }} فایل</small>
              @if($p->tags->isNotEmpty())<small class="product-index-tags">{{ $p->tags->pluck('name')->take(4)->implode('، ') }}</small>@endif
            </span>
          </div>
          <div>
            <a class="store-outline-btn" href="{{ route('admin.store.edit',$p) }}">اطلاعات</a> @if($p->type === 'course')<a class="store-primary-btn" href="{{ route('admin.store.course.edit',$p) }}">مدیریت دوره</a>@endif
          </div>
          <form method="post" action="{{ route('admin.store.destroy',$p) }}" onsubmit="return confirm('حذف شود؟')">@csrf @method('DELETE')
            <button type="submit" class="store-outline-btn" style="color:#c62828">حذف</button>
          </form>
        </div>
      @empty
        <div class="store-empty-state"><h2>محصولی نیست</h2><a class="store-primary-btn" href="{{ route('admin.store.create') }}">ایجاد اولین محصول</a></div>
      @endforelse
    </div>
    {{ $products->links() }}
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107center1">
<style>
.product-index-identity{display:flex;align-items:center;gap:12px;min-width:0}.product-index-identity>img,.product-index-placeholder{width:54px;height:54px;flex:0 0 54px;border-radius:10px;object-fit:cover;background:#f0f4fa}.product-index-placeholder{display:grid;place-items:center;color:#7a8da8}.product-index-identity>span{display:grid;gap:4px;min-width:0}.product-index-identity strong{font-size:.8rem}.product-index-identity small{overflow-wrap:anywhere}.product-index-tags{color:var(--st-blue)!important}
.product-center-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:16px}.product-center-stats>div{background:#fff;border:1px solid var(--st-line);border-radius:13px;padding:14px;display:grid;gap:6px}.product-center-stats span{font-size:.7rem;color:var(--st-muted)}.product-center-stats b{font-size:1.25rem;color:var(--st-ink)}.product-center-filters{display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:8px;margin-bottom:16px}.product-center-filters input,.product-center-filters select{min-width:0;border:1px solid var(--st-line);border-radius:10px;background:#fff;padding:10px 12px;font:inherit;font-size:.76rem;color:var(--st-ink)}@media(max-width:850px){.product-center-stats{grid-template-columns:repeat(3,1fr)}.product-center-filters{grid-template-columns:1fr 1fr}.product-center-filters input{grid-column:1/-1}}@media(max-width:480px){.product-center-stats{grid-template-columns:repeat(2,1fr)}}

.product-bulk-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 12px;margin:0 0 12px;border:1px solid var(--st-line);border-radius:12px;background:#fff;font-size:.72rem;color:var(--st-muted)}.product-bulk-actions label,.product-row-select{display:flex;align-items:center;gap:7px}.product-row-select{padding:0 4px}.product-row-select input,.product-bulk-actions input{width:17px;height:17px;accent-color:var(--st-blue)}.product-bulk-actions button:disabled{opacity:.45;cursor:not-allowed}
.product-center-stats{grid-template-columns:repeat(6,minmax(0,1fr))}.product-center-filters{grid-template-columns:2fr repeat(4,minmax(130px,1fr)) auto auto}
@media(max-width:1050px){.product-center-filters{grid-template-columns:repeat(3,minmax(0,1fr))}.product-center-filters input{grid-column:1/-1}}
@media(max-width:850px){.product-center-stats{grid-template-columns:repeat(3,1fr)}.product-center-filters{grid-template-columns:1fr 1fr}.product-center-filters input{grid-column:1/-1}}
@media(max-width:480px){.product-center-stats{grid-template-columns:repeat(2,1fr)}}

.product-pipeline-panel{display:grid;gap:12px;padding:16px;margin:0 0 16px;border:1px solid var(--st-line);border-radius:14px;background:#fff}
.product-pipeline-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.product-pipeline-heading>div{display:grid;gap:4px}.product-pipeline-heading strong,.product-pipeline-attention>strong{font-size:.82rem;color:var(--st-ink)}.product-pipeline-heading small,.product-pipeline-attention-row small{font-size:.68rem;color:var(--st-muted)}
.product-pipeline-heading>span{font-size:.68rem;padding:6px 9px;border-radius:8px;background:#f1f5f9;color:#475569}.product-pipeline-heading>span.is-configured{background:#ecfdf5;color:#047857}.product-pipeline-heading>span.is-unconfigured{background:#fff7ed;color:#c2410c}
.product-pipeline-metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.product-pipeline-metrics>div{display:grid;gap:5px;padding:10px;border-radius:10px;background:#f7f9fc}.product-pipeline-metrics span,.product-pipeline-meta{font-size:.67rem;color:var(--st-muted)}.product-pipeline-metrics b{font-size:1.1rem;color:var(--st-ink)}
.product-pipeline-meta{display:flex;gap:8px 18px;flex-wrap:wrap}.product-pipeline-meta b{color:var(--st-ink);font-weight:700}
.product-pipeline-attention{display:grid;gap:7px}.product-pipeline-attention-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:9px 10px;border:1px solid var(--st-line);border-radius:9px}.product-pipeline-attention-row>span:first-child{display:grid;gap:3px;min-width:0;flex:1}.product-pipeline-attention-row b{font-size:.72rem;color:var(--st-ink)}.product-pipeline-status{font-size:.67rem;color:#9a3412}.product-pipeline-attention-row a{font-size:.68rem;color:var(--st-blue);font-weight:700}.product-pipeline-empty{margin:0;font-size:.7rem;color:var(--st-muted)}
@media(max-width:760px){.product-pipeline-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}

</style>
@endpush

@push('scripts')
<script>
(() => {
  const form = document.getElementById('bulkArchiveForm');
  if (!form) return;
  const rows = Array.from(document.querySelectorAll('[data-product-row-select]'));
  const selectAll = form.querySelector('[data-product-select-all]');
  const count = form.querySelector('[data-product-selection-count]');
  const submit = form.querySelector('[data-product-bulk-submit]');
  const update = () => {
    const selected = rows.filter(input => input.checked).length;
    if (count) count.textContent = selected ? new Intl.NumberFormat('fa-IR').format(selected) + ' محصول انتخاب شده است.' : 'موردی انتخاب نشده است.';
    if (submit) submit.disabled = selected === 0;
    if (selectAll) {
      selectAll.checked = rows.length > 0 && selected === rows.length;
      selectAll.indeterminate = selected > 0 && selected < rows.length;
    }
  };
  rows.forEach(input => input.addEventListener('change', update));
  selectAll?.addEventListener('change', () => {
    rows.forEach(input => { input.checked = selectAll.checked; });
    update();
  });
  update();
})();
</script>
@endpush
