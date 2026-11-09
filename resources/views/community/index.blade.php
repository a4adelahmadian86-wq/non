@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/community-profile.css') }}?v=20261109community2">
@endpush
@section('content')
<div class="community-directory" dir="rtl">
 <header class="community-directory-head">
  <span>FARAST COMMUNITY</span>
  <h1>مشارکت‌کننده‌های جامعه فراست</h1>
  <p>اعضایی که با تجربه‌ها، پرسش‌ها و پاسخ‌های تأییدشده به انتخاب و یادگیری بهتر دیگران کمک کرده‌اند. رتبه‌بندی بر پایه واکنش‌های مفید واقعی و تعداد مشارکت‌های منتشرشده است.</p>
 </header>
 <section class="community-directory-list" aria-label="فهرست مشارکت‌کنندگان">
  @forelse($members as $member)
   <a class="community-directory-card" href="{{ route('community.member',$member->id) }}">
    <span class="community-profile-avatar">{{ $member->name ? mb_substr($member->name,0,1) : '؟' }}</span>
    <div><strong>{{ $member->name ?: 'عضو فراست' }}</strong><small><b>{{ number_format($member->helpful) }}</b> لایک مفید · {{ number_format($member->contributions) }} مشارکت منتشرشده</small></div>
   </a>
  @empty
   <p>هنوز مشارکت تأییدشده‌ای برای نمایش وجود ندارد.</p>
  @endforelse
 </section>
 <div class="community-directory-pager">{{ $members->links() }}</div>
</div>
@endsection
