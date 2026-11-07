@extends('layouts.app')
@section('content')
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
        <div class="store-cart-row">
          <div>
            <strong>{{ $p->title }}</strong>
            <small>{{ $p->type === 'course' ? 'دوره آموزشی' : 'فایل دانلودی' }} · {{ $p->sku }} · {{ $p->category?->name }} · {{ $p->status }} · {{ number_format($p->price_rials/10) }} تومان · {{ $p->files->where('purpose','product_download')->count() }} فایل</small>
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
.product-center-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:16px}.product-center-stats>div{background:#fff;border:1px solid var(--st-line);border-radius:13px;padding:14px;display:grid;gap:6px}.product-center-stats span{font-size:.7rem;color:var(--st-muted)}.product-center-stats b{font-size:1.25rem;color:var(--st-ink)}.product-center-filters{display:grid;grid-template-columns:2fr 1fr 1fr auto auto;gap:8px;margin-bottom:16px}.product-center-filters input,.product-center-filters select{min-width:0;border:1px solid var(--st-line);border-radius:10px;background:#fff;padding:10px 12px;font:inherit;font-size:.76rem;color:var(--st-ink)}@media(max-width:850px){.product-center-stats{grid-template-columns:repeat(3,1fr)}.product-center-filters{grid-template-columns:1fr 1fr}.product-center-filters input{grid-column:1/-1}}@media(max-width:480px){.product-center-stats{grid-template-columns:repeat(2,1fr)}}
</style>
@endpush
