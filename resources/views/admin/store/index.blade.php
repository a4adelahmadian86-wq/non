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
    <form method="get" class="store-search" style="margin-bottom:16px;max-width:420px">
      <input name="q" value="{{ $q }}" placeholder="جستجوی عنوان یا SKU">
      <button type="submit">جستجو</button>
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
<link rel="stylesheet" href="/css/store.css?v=20260929b">
@endpush
