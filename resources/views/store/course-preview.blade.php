@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container course-preview-page">
    <div class="store-section-head"><div><span class="eyebrow">جلسه نمونه رایگان · {{ $product->title }}</span><h1>{{ $lesson->title }}</h1><p>{{ $lesson->summary }}</p></div><a class="store-outline-btn" href="{{ route('store.product',$product->slug) }}">بازگشت به صفحه دوره</a></div>
    <article class="store-panel">
      @if($lesson->video_url)<div class="course-preview-media"><i class="fa-solid fa-circle-play"></i><span>ویدئوی نمونه</span><a href="{{ $lesson->video_url }}" target="_blank" rel="noopener noreferrer">مشاهده ویدئو در تب جدید</a></div>@endif
      @if($lesson->content)<div class="body">{!! nl2br(e($lesson->content)) !!}</div>@endif
      <div class="course-preview-cta"><span>برای مشاهده همه جلسات و منابع این دوره، به جمع دانشجویان دوره بپیوندید.</span><a class="store-primary-btn" href="{{ route('store.product',$product->slug) }}">مشاهده قیمت و تهیه دوره</a></div>
    </article>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107preview1">
<style>
.course-preview-page{max-width:900px}.course-preview-page .store-panel{padding:24px}.course-preview-page .body{font-size:.88rem;line-height:2.1;color:#40536d}.course-preview-media{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:16px;background:#f4f8fd;border:1px solid var(--st-line);border-radius:12px;margin-bottom:18px}.course-preview-media i{font-size:1.5rem;color:var(--st-blue)}.course-preview-media span{font-weight:800;flex:1}.course-preview-media a{font-size:.75rem;color:var(--st-blue)}.course-preview-cta{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;border-top:1px solid var(--st-line);margin-top:20px;padding-top:18px}.course-preview-cta span{font-size:.75rem;color:var(--st-muted)}
</style>
@endpush
