@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/community-profile.css') }}?v=20261109community3">
<link rel="stylesheet" href="{{ asset('css/my-discussions.css') }}?v=20261109mine1">
@endpush
@section('content')
<div class="community-mine" dir="rtl">
 <header class="community-mine-head">
  <div><span>FARAST COMMUNITY · PERSONAL SPACE</span><h1>مشارکت‌های من</h1><p>پیگیری وضعیت تجربه‌ها، دیدگاه‌ها، پرسش‌ها و پاسخ‌هایی که در جامعه فراست ثبت کرده‌اید.</p></div>
  <a href="{{ route('community.index') }}">مشاهده جامعه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
 </header>
 @if(session('discussion_status'))<div class="community-mine-flash" role="status">{{ session('discussion_status') }}</div>@endif
 <section class="community-mine-stats" aria-label="آمار مشارکت‌های شما">
  <a href="{{ route('community.mine') }}" class="{{ $status==='all'?'is-active':'' }}"><strong>{{ number_format($stats['all']) }}</strong><span>همه مشارکت‌ها</span></a>
  <a href="{{ route('community.mine',['status'=>'pending']) }}" class="{{ $status==='pending'?'is-active':'' }}"><strong>{{ number_format($stats['pending']) }}</strong><span>در انتظار بررسی</span></a>
  <a href="{{ route('community.mine',['status'=>'approved']) }}" class="{{ $status==='approved'?'is-active':'' }}"><strong>{{ number_format($stats['approved']) }}</strong><span>منتشرشده</span></a>
  <a href="{{ route('community.mine',['status'=>'rejected']) }}" class="{{ $status==='rejected'?'is-active':'' }}"><strong>{{ number_format($stats['rejected']) }}</strong><span>منتشرنشده</span></a>
 </section>
 <form class="community-mine-filters" method="get" action="{{ route('community.mine') }}">
  <label>جست‌وجو<input type="search" name="q" maxlength="100" value="{{ $search }}" placeholder="جست‌وجو در عنوان و متن مشارکت"></label>
  <label>نوع مشارکت<select name="kind"><option value="">همه انواع</option><option value="review" @selected($kind==='review')>تجربه محصول</option><option value="question" @selected($kind==='question')>پرسش</option><option value="comment" @selected($kind==='comment')>دیدگاه</option><option value="reply" @selected($kind==='reply')>پاسخ</option></select></label>
  <label>بخش محتوا<select name="target_type"><option value="">همه بخش‌ها</option><option value="product" @selected($targetType==='product')>محصول</option><option value="article" @selected($targetType==='article')>مقاله</option></select></label>
  <button type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> اعمال فیلتر</button>
  @if($search!==''||$kind!==''||$targetType!=='')<a href="{{ route('community.mine',['status'=>$status]) }}">پاک‌کردن فیلترها</a>@endif
 </form>
 <section class="community-mine-list" aria-label="فهرست مشارکت‌های من">
  @forelse($discussions as $item)
   <article class="community-mine-item">
    <div class="community-mine-item-top">
     <span class="community-mine-kind">{{ ['review'=>'تجربه محصول','question'=>'پرسش','comment'=>'دیدگاه','reply'=>'پاسخ'][$item->kind] ?? 'مشارکت' }}</span>
     <span class="community-mine-status status-{{ $item->status }}">{{ ['pending'=>'در انتظار بررسی','approved'=>'منتشرشده','rejected'=>'ردشده'][$item->status] ?? $item->status }}</span>
     <time>{{ $item->created_at?->translatedFormat('d F Y · H:i') }}</time>
    </div>
    @if($item->title)<h2>{{ $item->title }}</h2>@endif
    <p>{{ $item->body }}</p>
    <div class="community-mine-item-foot">
     <span>{{ $item->target_label }} · {{ $item->target_type==='product'?'فروشگاه':'مقالات' }}</span>
     <div>
      @if($item->parent_url)<a href="{{ $item->discussion_url }}">مشاهده در محتوا <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>@elseif($item->target_url)<a href="{{ $item->target_url }}#discussion-{{ $item->id }}">مشاهده در محتوا <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>@endif
      @if($item->status==='pending')
       <form method="post" action="{{ route('community.mine.withdraw',$item) }}" onsubmit="return confirm('این مشارکت از صف بررسی لغو شود؟')">@csrf @method('DELETE')<button type="submit">لغو مشارکت</button></form>
      @endif
     </div>
    </div>
   </article>
  @empty
   <div class="community-mine-empty"><i class="fa-regular fa-comments" aria-hidden="true"></i><strong>مشارکتی با این شرایط پیدا نشد</strong><p>فیلترها را تغییر دهید یا در صفحهٔ محصول و مقاله، تجربه یا پرسش خود را ثبت کنید.</p></div>
  @endforelse
 </section>
 <div class="community-mine-pager">{{ $discussions->links() }}</div>
 <p class="community-mine-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> فقط مشارکت‌های در انتظار بررسی قابل لغو هستند؛ محتوای منتشرشده و سوابق رسیدگی‌شده برای حفظ پیوستگی گفت‌وگو و سابقهٔ نظارت حذف نمی‌شوند.</p>
</div>
@endsection
