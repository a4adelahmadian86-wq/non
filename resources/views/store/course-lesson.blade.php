@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container course-lesson-page">
    <div class="store-section-head"><div><span class="eyebrow">{{ $product->title }}</span><h1>{{ $lesson->title }}</h1><p>{{ $lesson->summary }}</p></div><a class="store-outline-btn" href="{{ route('store.course.learn',$product) }}">فهرست دوره</a></div>
    <article class="store-panel course-lesson-content">
      @if($lesson->video_url)<div class="course-external-media"><i class="fa-solid fa-circle-play"></i><span>محتوای ویدئویی این جلسه</span><a href="{{ $lesson->video_url }}" target="_blank" rel="noopener noreferrer">باز کردن پخش‌کننده امن در تب جدید</a></div>@endif
      @if($lesson->content)<div class="body">{!! nl2br(e($lesson->content)) !!}</div>@endif
      @if($lesson->resources->isNotEmpty())<div class="course-lesson-resources"><h2>فایل‌ها و منابع جلسه</h2>@foreach($lesson->resources as $resource)<a href="{{ route('store.course.resource',[$product,$lesson,$resource]) }}"><i class="fa-solid fa-paperclip"></i> {{ $resource->original_name }} <small>{{ number_format($resource->size_bytes/1024,1) }} KB</small></a>@endforeach</div>@endif
      <div class="course-lesson-completion"><span>{{ $progress->completed_at?'این جلسه تکمیل شده است.':'پس از مشاهده کامل جلسه، آن را تکمیل‌شده علامت بزنید.' }}</span><button type="button" id="completeLesson" data-url="{{ route('store.course.progress',[$product,$lesson]) }}" {{ $progress->completed_at?'disabled':'' }}> <i class="fa-solid fa-check"></i> {{ $progress->completed_at?'تکمیل شده':'علامت‌گذاری به‌عنوان تکمیل‌شده' }}</button></div>
    </article>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107lesson1">
<style>
.course-lesson-page{max-width:980px}.course-lesson-content{padding:24px}.course-lesson-content .body{font-size:.88rem;line-height:2.1;color:#40536d;white-space:normal}.course-external-media{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:18px;background:#f4f8fd;border:1px solid var(--st-line);border-radius:12px;margin-bottom:18px}.course-external-media>i{font-size:1.5rem;color:var(--st-blue)}.course-external-media span{font-weight:800;font-size:.82rem;flex:1}.course-external-media a{color:var(--st-blue);font-size:.75rem}.course-lesson-resources{border-top:1px solid var(--st-line);margin-top:20px;padding-top:16px}.course-lesson-resources h2{font-size:.9rem}.course-lesson-resources a{display:flex;gap:8px;align-items:center;padding:10px;border:1px solid var(--st-line);border-radius:9px;text-decoration:none;color:var(--st-ink);margin-top:7px;font-size:.76rem}.course-lesson-resources small{margin-right:auto;color:var(--st-muted)}.course-lesson-completion{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid var(--st-line);margin-top:22px;padding-top:16px}.course-lesson-completion span{font-size:.75rem;color:var(--st-muted)}.course-lesson-completion button{border:0;border-radius:10px;background:#0f9f6e;color:#fff;padding:11px 16px;font:inherit;font-size:.75rem;font-weight:800;cursor:pointer}.course-lesson-completion button:disabled{opacity:.65;cursor:default}@media(max-width:600px){.course-lesson-content{padding:15px}}
</style>
@endpush
@push('scripts')
<script>
(()=>{const button=document.getElementById('completeLesson');if(!button)return;button.addEventListener('click',async()=>{button.disabled=true;const label=button.innerHTML;button.innerHTML='در حال ثبت...';try{const response=await fetch(button.dataset.url,{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'','Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({completed:true})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'ثبت پیشرفت انجام نشد.');button.innerHTML='<i class="fa-solid fa-check"></i> تکمیل شده';}catch(error){button.disabled=false;button.innerHTML=label;alert(error.message||'خطا در ثبت پیشرفت');}});})();
</script>
@endpush
