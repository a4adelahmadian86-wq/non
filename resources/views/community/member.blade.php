@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/community-profile.css') }}?v=20261109community1">
@endpush
@section('content')
<div class="community-profile" dir="rtl">
 <header class="community-profile-hero">
  <span class="community-profile-avatar">{{ $user->name ? mb_substr($user->name,0,1) : '؟' }}</span>
  <div><span class="community-profile-eyebrow">FARAST COMMUNITY</span><h1>{{ $user->name ?: 'عضو فراست' }}</h1><p>عضو جامعهٔ فراست از {{ $user->created_at?->translatedFormat('F Y') }}</p></div>
 </header>
 <section class="community-profile-stats" aria-label="آمار مشارکت">
  <article><strong>{{ number_format($stats['contributions']) }}</strong><span>مشارکت‌های منتشرشده</span></article>
  <article><strong>{{ number_format($stats['reviews']) }}</strong><span>تجربه و دیدگاه محصول</span></article>
  <article><strong>{{ number_format($stats['questions']) }}</strong><span>پرسش‌ها</span></article>
  <article><strong>{{ number_format($stats['replies']) }}</strong><span>پاسخ‌ها</span></article>
  <article><strong>{{ number_format($stats['helpful']) }}</strong><span>لایک‌های دریافتی</span></article>
 </section>
 <section class="community-profile-activity">
  <div class="community-profile-section-head"><h2>آخرین مشارکت‌ها</h2><span>فقط محتوای تأییدشده</span></div>
  @foreach($activity as $item)
   <article class="community-profile-item">
    <div><span>{{ ['review'=>'تجربه محصول','question'=>'پرسش','comment'=>'دیدگاه','reply'=>'پاسخ'][$item->kind] ?? 'مشارکت' }}</span><time>{{ $item->created_at?->translatedFormat('d F Y') }}</time></div>
    <p>{{ $item->body }}</p>
    @if($item->target_url)<a href="{{ $item->target_url }}#discussion-{{ $item->id }}">مشاهده در {{ $item->target_label }} <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>@endif
   </article>
  @endforeach
 </section>
</div>
@endsection
