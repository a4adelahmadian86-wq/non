@extends('layouts.admin')
@section('admin-content')
<section class="discussion-admin-page" dir="rtl">
 <div class="discussion-admin-heading"><div><span class="eyebrow">ایمنی و کیفیت جامعه</span><h1>گزارش‌های کاربران</h1><p>گزارش‌ها را بررسی کنید؛ هیچ محتوایی صرفاً با ثبت گزارش به‌صورت خودکار حذف نمی‌شود.</p></div><a class="store-outline-btn" href="{{ route('admin.discussions.index') }}">بازگشت به نظرات و پرسش‌ها</a></div>
 @if(session('status'))<div class="store-alert success" role="status">{{ session('status') }}</div>@endif
 <div class="discussion-admin-stats">@foreach(['pending'=>'در انتظار رسیدگی','resolved'=>'رسیدگی‌شده','dismissed'=>'رد گزارش'] as $key=>$title)<a class="{{ $status===$key?'is-active':'' }}" href="{{ route('admin.discussion-reports.index',['status'=>$key]) }}"><span>{{ $title }}</span><strong>{{ number_format($stats[$key]) }}</strong></a>@endforeach</div>
 <div class="discussion-report-admin-list">
 @forelse($reports as $report)
  <article class="discussion-report-admin-card">
   <div class="discussion-report-admin-meta"><span>گزارش‌دهنده: {{ $report->reporter?->name ?: 'کاربر حذف‌شده' }}</span><span>{{ $report->created_at?->format('Y/m/d H:i') }}</span><span>دلیل: {{ ['spam'=>'تبلیغات یا هرزنامه','abuse'=>'توهین یا محتوای آزاردهنده','harassment'=>'آزار یا تهدید','misinformation'=>'اطلاعات گمراه‌کننده','other'=>'سایر موارد'][$report->reason] ?? $report->reason }}</span></div>
   <div class="discussion-report-context"><strong>{{ $report->discussion?->user?->name ?: 'نویسنده نامشخص' }}</strong><p>{{ $report->discussion?->body ?: 'محتوا دیگر در دسترس نیست.' }}</p>@if($report->details)<small>توضیح گزارش‌دهنده: {{ $report->details }}</small>@endif</div>
   @if($status==='pending' && $report->discussion)
    <div class="discussion-report-admin-actions">
     <form method="post" action="{{ route('admin.discussion-reports.moderate',$report) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="dismiss"><button class="discussion-report-dismiss">رد گزارش؛ حفظ محتوا</button></form>
     <form method="post" action="{{ route('admin.discussion-reports.moderate',$report) }}" onsubmit="return confirm('محتوا از سایت برداشته شود؟')">@csrf @method('PATCH')<input type="hidden" name="action" value="remove_content"><button class="discussion-report-remove">تأیید تخلف و حذف محتوا</button></form>
    </div>
   @else
    <small>وضعیت: {{ $report->status==='resolved'?'رسیدگی‌شده':'ردشده' }} @if($report->reviewer) · بررسی توسط {{ $report->reviewer->name }} @endif</small>
   @endif
  </article>
 @empty <div class="store-empty-state"><h2>گزارشی در این فهرست نیست</h2></div> @endforelse
 </div>
 {{ $reports->links() }}
</section>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/content-discussions.css') }}?v=20261109community4">@endpush
