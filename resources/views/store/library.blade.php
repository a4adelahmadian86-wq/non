@extends('layouts.app')

@section('content')
<section class="store-page" dir="rtl">
    <div class="store-container">
        <div class="store-section-head">
            <div><span class="eyebrow">کتابخانه دیجیتال</span><h1>کتابخانه من</h1><p>فایل‌های دانلودی و دوره‌های آموزشی خریداری‌شده در یک فضای امن.</p></div>
            <a class="store-outline-btn" href="{{ route('store') }}"><i class="fa-solid fa-store"></i> بازگشت به فروشگاه</a>
        </div>

        @if(session('status'))
            <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="store-alert error"><i class="fa-solid fa-triangle-exclamation"></i>{{ $errors->first() }}</div>
        @endif

        @if($items->count())
            <div class="store-product-grid">
                @foreach($items as $item)
                    <article class="store-product-card">
                        <div class="store-product-cover">
                            @if($item->product?->cover_path)
                                <img src="{{ asset($item->product->cover_path) }}" alt="{{ $item->product->title }}" loading="lazy">
                            @else
                                <i class="fa-solid fa-{{ $item->product?->type === 'course' ? 'graduation-cap' : 'file-lines' }}"></i>
                            @endif
                        </div>
                        <div class="store-product-copy">
                            <small>{{ $item->product?->type === 'course' ? 'دوره آموزشی' : ($item->product?->category?->name ?? 'فایل دیجیتال') }}</small>
                            <h2>{{ $item->product?->title ?? 'محصول حذف‌شده' }}</h2>
                            <p>نسخه {{ $item->product?->version ?: 'اصلی' }} · دسترسی از {{ optional($item->granted_at)->format('Y/m/d') }}</p>
                            @if($item->product?->type === 'course')<a class="store-primary-btn" href="{{ route('store.course.learn',$item->product) }}"><i class="fa-solid fa-graduation-cap"></i> ورود به دوره</a>@else<button type="button" class="store-primary-btn library-download" data-library-download="{{ $item->id }}"><i class="fa-solid fa-download"></i> دریافت امن</button>@endif
                        </div>
                    </article>
                @endforeach
            </div>
            {{ $items->links() }}
        @else
            <div class="store-empty-state"><i class="fa-solid fa-box-open"></i><h2>کتابخانه هنوز خالی است</h2><p>پس از خرید فایل یا دوره آموزشی، دسترسی آن از اینجا فعال می‌شود.</p><a class="store-primary-btn" href="{{ route('store') }}">مشاهده فروشگاه</a></div>
        @endif
    </div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929">
@endpush
@push('scripts')
<script>
(()=>{
 const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
 document.querySelectorAll('.library-download').forEach(btn=>btn.addEventListener('click',async()=>{
   btn.disabled=true;
   const original=btn.innerHTML;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> آماده‌سازی...';
   try{
     const r=await fetch('/library/'+btn.dataset.libraryDownload+'/download',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}});
     const data=await r.json();
     if(!r.ok||!data.url) throw new Error(data.message||'امکان دریافت فایل وجود ندارد.');
     location.href=data.url;
   }catch(e){alert(e.message||'خطا در دریافت فایل.');btn.disabled=false;btn.innerHTML=original;}
 });
})();
</script>
@endpush
