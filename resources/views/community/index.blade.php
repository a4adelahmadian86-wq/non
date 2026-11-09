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
  <form class="community-directory-filters" method="get" action="{{ route('community.index') }}">
   <label>جست‌وجوی نام عضو<input type="search" name="q" maxlength="80" value="{{ $search ?? '' }}" placeholder="نام مشارکت‌کننده"></label>
   <label>نوع مشارکت<select name="kind"><option value="">همه مشارکت‌ها</option><option value="review" @selected(($kind ?? '')==='review')>تجربه محصول</option><option value="question" @selected(($kind ?? '')==='question')>پرسش</option><option value="comment" @selected(($kind ?? '')==='comment')>دیدگاه</option><option value="reply" @selected(($kind ?? '')==='reply')>پاسخ</option></select></label>
   <label>ترتیب نمایش<select name="sort"><option value="helpful" @selected(($sort ?? 'helpful')==='helpful')>مفیدترین اعضا</option><option value="active" @selected(($sort ?? '')==='active')>فعال‌ترین اعضا</option></select></label>
   <button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> جست‌وجو</button>
   @if(($search ?? '')!==''||($kind ?? '')!=='')<a href="{{ route('community.index',['sort'=>$sort ?? 'helpful']) }}">پاک‌کردن فیلترها</a>@endif
  </form>
   @auth
   <a class="community-mine-link" href="{{ route('community.mine') }}"><i class="fa-solid fa-user-pen" aria-hidden="true"></i> مشارکت‌های من</a>
  @endauth
 </header>
 <section class="community-directory-list" aria-label="فهرست مشارکت‌کنندگان">
  @forelse($members as $member)
   <a class="community-directory-card" href="{{ route('community.member',$member->id) }}">
    <span class="community-profile-avatar">{{ $member->name ? mb_substr($member->name,0,1) : '؟' }}</span>
    <div><strong>{{ $member->name ?: 'عضو فراست' }}</strong><small><b>{{ number_format($member->helpful) }}</b> لایک مفید · {{ number_format($member->contributions) }} مشارکت منتشرشده</small></div>
   </a>
  @empty
   <p>عضوی با این شرایط پیدا نشد. فیلترها را تغییر دهید یا جست‌وجو را پاک کنید.</p>
  @endforelse
 </section>
 <div class="community-directory-pager">{{ $members->links() }}</div>
</div>
@endsection
