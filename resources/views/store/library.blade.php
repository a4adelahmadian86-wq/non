@extends('layouts.app')

@section('content')
<section class="store-page library-page" dir="rtl">
    <div class="store-container">
        <div class="store-section-head library-heading">
            <div>
                <span class="eyebrow">کتابخانه دیجیتال شما</span>
                <h1>کتابخانه من</h1>
                <p>فایل‌های دانلودی و دوره‌های آموزشی خریداری‌شده در یک فضای امن.</p>
            </div>
            <div class="library-heading-actions">
                <span class="library-count"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> {{ number_format($items->total()) }} محصول</span>
                <a class="store-outline-btn" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> بازگشت به فروشگاه</a>
            </div>
        </div>

        @if(session('status'))
            <div class="store-alert success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="store-alert error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>{{ $errors->first() }}</div>
        @endif
        <div id="libraryDownloadFeedback" class="library-feedback" role="status" aria-live="polite" aria-atomic="true" hidden></div>

        @if($items->count())
            <div class="store-product-grid library-product-grid">
                @foreach($items as $item)
                    @php
                        $fileBytes = (int) ($item->file?->size_bytes ?? 0);
                        $fileSize = $fileBytes >= 1048576
                            ? number_format($fileBytes / 1048576, 2).' مگابایت'
                            : ($fileBytes > 0 ? number_format($fileBytes / 1024, 0).' کیلوبایت' : null);
                        $fileAvailable = $item->file && $item->file->is_active;
                    @endphp
                    <article class="store-product-card library-product-card">
                        <div class="store-product-cover library-product-cover">
                            @if($item->product?->cover_path)
                                <img src="{{ asset($item->product->cover_path) }}" alt="{{ $item->product->title }}" loading="lazy">
                            @else
                                <div class="library-cover-placeholder"><i class="fa-solid fa-{{ $item->product?->type === 'course' ? 'graduation-cap' : 'file-lines' }}" aria-hidden="true"></i><span>FARAST DIGITAL</span></div>
                            @endif
                            <span class="library-cover-badge"><i class="fa-solid fa-check" aria-hidden="true"></i> خریداری‌شده</span>
                        </div>
                        <div class="store-product-copy library-product-copy">
                            <small>{{ $item->product?->type === 'course' ? 'دوره آموزشی' : ($item->product?->category?->name ?? 'فایل دیجیتال') }}</small>
                            <h2>{{ $item->product?->title ?? 'محصول حذف‌شده' }}</h2>
                            <p>نسخه {{ $item->file?->version ?: ($item->product?->version ?: 'اصلی') }} · خرید {{ optional($item->granted_at)->format('Y/m/d') }}</p>
                            @if($item->product?->type !== 'course')
                                <div class="library-file-meta">
                                    <span><i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i> {{ $item->file?->original_name ?: 'فایل محصول' }}</span>
                                    @if($fileSize)<span><i class="fa-solid fa-database" aria-hidden="true"></i> {{ $fileSize }}</span>@endif
                                </div>
                            @endif
                            @if($item->product?->type === 'course')
                                <a class="store-primary-btn" href="{{ route('store.course.learn',$item->product) }}"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> ورود به دوره</a>
                            @elseif($fileAvailable)
                                <button type="button" class="store-primary-btn library-download" data-library-download="{{ $item->id }}" data-label="دریافت امن فایل">
                                    <i class="fa-solid fa-download" aria-hidden="true"></i> دریافت امن فایل
                                </button>
                            @else
                                <div class="library-unavailable" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> فایل این محصول فعلاً در دسترس نیست.</div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="library-pagination">{{ $items->links() }}</div>
        @else
            <div class="store-empty-state library-empty">
                <span class="library-empty-icon"><i class="fa-solid fa-box-open" aria-hidden="true"></i></span>
                <h2>کتابخانه شما هنوز خالی است</h2>
                <p>پس از خرید فایل یا دوره آموزشی، دسترسی آن از اینجا فعال می‌شود.</p>
                <a class="store-primary-btn" href="{{ route('store') }}"><i class="fa-solid fa-compass" aria-hidden="true"></i> کشف محصولات فروشگاه</a>
            </div>
        @endif
    </div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261108-library2">
@endpush
@push('scripts')
<script>
(()=>{
 const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
 const feedback=document.getElementById('libraryDownloadFeedback');
 const showFeedback=(message,type='error')=>{
   if(!feedback)return;
   feedback.hidden=false;
   feedback.className='library-feedback '+type;
   feedback.textContent=message;
 };
 document.querySelectorAll('.library-download').forEach(btn=>btn.addEventListener('click',async()=>{
   if(btn.disabled)return;
   const original=btn.dataset.label||'دریافت امن فایل';
   btn.disabled=true;
   btn.setAttribute('aria-busy','true');
   btn.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> آماده‌سازی فایل...';
   if(feedback)feedback.hidden=true;
   try{
     const response=await fetch('/library/'+encodeURIComponent(btn.dataset.libraryDownload)+'/download',{
       method:'POST',
       credentials:'same-origin',
       headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}
     });
     const body=await response.text();
     let data={};
     try{data=body?JSON.parse(body):{};}catch(_){data={};}
     if(!response.ok||!data.url)throw new Error(data.message||'دریافت فایل انجام نشد. لطفاً دوباره تلاش کنید.');
     showFeedback('لینک امن آماده شد؛ انتقال به دریافت فایل...','success');
     window.location.assign(data.url);
   }catch(error){
     showFeedback(error.message||'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.','error');
     btn.disabled=false;
     btn.removeAttribute('aria-busy');
     btn.innerHTML='<i class="fa-solid fa-download" aria-hidden="true"></i> '+original;
   }
 }));
})();
</script>
@endpush
