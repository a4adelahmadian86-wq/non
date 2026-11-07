@extends('layouts.app')
@section('content')
@include('admin.store.partials.workspace-nav')
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
      <div><span>پیش‌نویس</span><b>{{ number_format($stats['drafts']) }}</b></div>
      <div><span>دوره آموزشی</span><b>{{ number_format($stats['courses']) }}</b></div>
      <div><span>فایل دانلودی</span><b>{{ number_format($stats['files']) }}</b></div>
      <div><span>سفارش‌های موفق</span><b>{{ number_format($stats['paid_orders']) }}</b></div>
      <div><span>دسترسی‌های فعال</span><b>{{ number_format($stats['active_accesses']) }}</b></div>
      <div><span>فروش ثبت‌شده</span><b>{{ number_format($stats['revenue_rials']/10) }} <small>تومان</small></b></div>
    </div>
    <form method="get" class="product-center-filters">
      <input name="q" value="{{ $q }}" placeholder="جستجوی عنوان یا شناسه محصول">
      <select name="type"><option value="">همه نوع‌ها</option><option value="digital_file" @selected($type==='digital_file')>فایل دانلودی</option><option value="course" @selected($type==='course')>دوره آموزشی</option></select>
      <select name="status"><option value="">همه وضعیت‌ها</option><option value="draft" @selected($status==='draft')>پیش‌نویس</option><option value="published" @selected($status==='published')>منتشرشده</option><option value="archived" @selected($status==='archived')>بایگانی</option></select>
      <button type="submit" class="store-primary-btn">اعمال فیلتر</button>
      <a class="store-outline-btn" href="{{ route('admin.store.index') }}">پاک‌کردن</a>
    </form>
    <div class="store-cart-list">
      @forelse($products as $p)
        <article class="store-product-workspace-card">
          <div class="store-product-workspace-cover">
            @if($p->cover_path)<img src="{{ asset($p->cover_path) }}" alt="" loading="lazy">@else<i class="fa-solid {{ $p->type === 'course' ? 'fa-graduation-cap' : 'fa-file-arrow-down' }}"></i>@endif
          </div>
          <div class="store-product-workspace-main">
            <div class="store-product-workspace-title">
              <div><span class="store-product-type">{{ $p->type === 'course' ? 'دوره آموزشی' : 'محصول دانلودی' }} · {{ $p->sku }}</span><h3>{{ $p->title }}</h3><small>{{ $p->category?->name ?: 'بدون دسته‌بندی' }}</small></div>
              <span class="store-status-pill status-{{ $p->status }}">{{ ['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'بایگانی'][$p->status] ?? $p->status }}</span>
            </div>
            <div class="store-product-workspace-metrics">
              <span><b>{{ number_format($p->price_rials/10) }}</b> تومان</span>
              <span><b>{{ number_format($p->paid_order_items_count ?? 0) }}</b> فروش موفق</span>
              <span><b>{{ number_format($p->active_access_count ?? 0) }}</b> دسترسی فعال</span>
              <span><b>{{ number_format(($p->paid_sales_rials ?? 0)/10) }}</b> تومان درآمد</span>
            </div>
          </div>
          <div class="store-product-workspace-actions">
            <a class="store-outline-btn" href="{{ route('admin.store.edit',$p) }}"><i class="fa-solid fa-pen"></i> ویرایش</a>
            @if($p->type === 'course')<a class="store-primary-btn" href="{{ route('admin.store.course.edit',$p) }}"><i class="fa-solid fa-graduation-cap"></i> برنامه دوره</a>@endif
            @if($p->status === 'published')<a class="store-icon-action" href="{{ route('store.product',$p->slug) }}" target="_blank" rel="noopener" aria-label="مشاهده صفحه عمومی"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>@endif
          </div>
        </article>
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
.product-center-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:16px}.product-center-stats>div{background:#fff;border:1px solid var(--st-line);border-radius:13px;padding:14px;display:grid;gap:6px}.product-center-stats span{font-size:.7rem;color:var(--st-muted)}.product-center-stats b{font-size:1.25rem;color:var(--st-ink)}.product-center-filters{display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:8px;margin-bottom:16px}.product-center-filters input,.product-center-filters select{min-width:0;border:1px solid var(--st-line);border-radius:10px;background:#fff;padding:10px 12px;font:inherit;font-size:.76rem;color:var(--st-ink)}@media(max-width:850px){.product-center-stats{grid-template-columns:repeat(3,1fr)}.product-center-filters{grid-template-columns:1fr 1fr}.product-center-filters input{grid-column:1/-1}}@media(max-width:480px){.product-center-stats{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush
