@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container course-student">
    <div class="store-section-head"><div><span class="eyebrow">کتابخانه آموزشی شما</span><h1>{{ $product->title }}</h1><p>{{ $enrollment->course->subtitle ?: 'مسیر یادگیری شما، جلسه به جلسه' }}</p></div><a class="store-outline-btn" href="{{ route('library') }}">بازگشت به کتابخانه</a></div>
    <div class="store-panel course-progress-card"><div><strong>پیشرفت یادگیری</strong><span>{{ $completed }} از {{ $total }} جلسه تکمیل شده</span></div><b>{{ $progressPercent }}٪</b><div class="course-progress-track"><i style="width:{{ $progressPercent }}%"></i></div></div>
    <div class="course-student-layout">
      <main class="store-panel"><h2>برنامه آموزشی</h2>
        @forelse($sections as $section)
          <section class="course-student-section"><h3>{{ $section->title }}</h3>
            @foreach($section->lessons as $lesson)
              @php($done = $enrollment->progress->firstWhere('lesson_id',$lesson->id)?->completed_at)
              <a class="course-student-lesson" href="{{ route('store.course.lesson',[$product,$lesson]) }}"><span class="course-student-lesson-icon"><i class="fa-solid fa-{{ $lesson->lesson_type==='video'?'play':($lesson->lesson_type==='audio'?'headphones':'file-lines') }}"></i></span><span><b>{{ $lesson->title }}</b><small>{{ $lesson->lesson_type==='video'?'ویدئو':($lesson->lesson_type==='audio'?'صوت':($lesson->lesson_type==='article'?'متن آموزشی':'محتوای آموزشی')) }} @if($lesson->duration_seconds) · {{ gmdate('H:i:s',$lesson->duration_seconds) }} @endif</small></span><i class="fa-solid fa-{{ $done?'circle-check':'circle-play' }}" style="color:{{ $done?'#15956b':'#7a8da8' }}"></i></a>
            @endforeach
          </section>
        @empty
          <p class="course-empty">محتوای منتشرشده‌ای برای این دوره وجود ندارد.</p>
        @endforelse
      </main>
      <aside class="store-panel course-student-aside"><strong>اطلاعات دوره</strong><p>مدرس: {{ $enrollment->course->instructor_name ?: 'تیم آموزشی فراست' }}</p><p>سطح: {{ ['beginner'=>'مقدماتی','intermediate'=>'متوسط','advanced'=>'پیشرفته','all'=>'همه سطوح'][$enrollment->course->level] ?? 'مقدماتی' }}</p><p>دسترسی: {{ $enrollment->expires_at ? $enrollment->expires_at->format('Y/m/d') : 'بدون انقضا' }}</p>@if($enrollment->course->certificate_enabled)<p><i class="fa-solid fa-award"></i> گواهی پایان دوره فعال است.</p>@endif</aside>
    </div>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107student1">
<style>
.course-student{max-width:1080px}.course-progress-card{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}.course-progress-card strong,.course-progress-card span{display:block}.course-progress-card strong{font-size:.85rem}.course-progress-card span{font-size:.7rem;color:var(--st-muted);margin-top:4px}.course-progress-card>b{font-size:1.3rem;color:var(--st-blue)}.course-progress-track{grid-column:1/-1;height:8px;border-radius:99px;background:#edf1f7;overflow:hidden}.course-progress-track i{display:block;height:100%;background:linear-gradient(90deg,#1769ff,#16a77a);border-radius:99px}.course-student-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:14px;margin-top:14px}.course-student-layout>.store-panel{margin:0}.course-student-section{margin-top:16px}.course-student-section h3{font-size:.84rem;margin:0 0 8px}.course-student-lesson{display:flex;align-items:center;gap:10px;padding:12px;border:1px solid var(--st-line);border-radius:11px;text-decoration:none;color:var(--st-ink);margin-top:7px}.course-student-lesson-icon{width:34px;height:34px;border-radius:10px;background:#edf4ff;color:var(--st-blue);display:grid;place-items:center}.course-student-lesson>span:nth-child(2){flex:1}.course-student-lesson b,.course-student-lesson small{display:block}.course-student-lesson b{font-size:.78rem}.course-student-lesson small{font-size:.66rem;color:var(--st-muted);margin-top:3px}.course-student-aside{height:max-content}.course-student-aside p{font-size:.73rem;color:var(--st-muted);padding:8px 0;border-bottom:1px solid var(--st-line)}@media(max-width:760px){.course-student-layout{grid-template-columns:1fr}}
</style>
@endpush
