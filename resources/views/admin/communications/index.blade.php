@extends('layouts.admin')

@section('admin-content')
<style>
.ac-shell{direction:rtl;color:#172033;display:grid;gap:20px}.ac-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.ac-head h1{margin:0;font-size:1.45rem}.ac-head p{margin:6px 0 0;color:#64748b}.ac-actions{display:flex;gap:8px;flex-wrap:wrap}.ac-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid #dbe3ee;border-radius:10px;text-decoration:none;color:#24324a;background:#fff;font-size:.88rem}.ac-btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.ac-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.ac-stat,.ac-panel{background:#fff;border:1px solid #e4eaf2;border-radius:15px;padding:16px}.ac-stat strong{display:block;font-size:1.55rem}.ac-stat span{color:#64748b;font-size:.82rem}.ac-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:16px}.ac-panel h2{font-size:1rem;margin:0 0 13px}.ac-list{display:grid;gap:0}.ac-item{display:flex;align-items:flex-start;gap:11px;padding:12px 0;border-top:1px solid #edf1f6;text-decoration:none;color:inherit}.ac-item:first-child{border-top:0}.ac-icon{width:36px;height:36px;flex:0 0 36px;border-radius:11px;background:#f1f5f9;display:grid;place-items:center;color:#475569}.ac-copy{min-width:0;flex:1}.ac-copy strong{display:block;font-size:.9rem}.ac-copy small{display:block;color:#64748b;margin-top:4px;line-height:1.7}.ac-meta{font-size:.75rem;color:#94a3b8;white-space:nowrap}.ac-empty{padding:18px;text-align:center;color:#94a3b8;background:#f8fafc;border-radius:10px}.ac-tag{font-size:.72rem;border-radius:20px;padding:3px 8px;background:#eff6ff;color:#1d4ed8}.ac-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px;flex-wrap:wrap}.ac-footer a{color:#1d4ed8;text-decoration:none;font-size:.85rem}@media(max-width:900px){.ac-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ac-grid{grid-template-columns:1fr}}@media(max-width:520px){.ac-stats{gap:8px}.ac-stat{padding:12px}.ac-meta{display:none}}
</style>
<div class="ac-shell">
    <header class="ac-head">
        <div><h1>مرکز ارتباطات فراست</h1><p>یک نمای هماهنگ از تیکت‌های پشتیبانی، گفت‌وگوهای داخلی و اعلان‌ها؛ با حفظ منبع داده و گردش‌کار اصلی هر بخش.</p></div>
        <div class="ac-actions">
            <a class="ac-btn primary" href="{{ route('admin.support.management') }}"><i class="fa-solid fa-headset"></i> میز تیکت‌ها</a>
            <a class="ac-btn" href="{{ route('messenger.index') }}"><i class="fa-solid fa-comments"></i> پیام‌رسان داخلی</a>
            <a class="ac-btn" href="{{ route('platform.notifications') }}"><i class="fa-regular fa-bell"></i> مرکز اعلان‌ها</a>
        </div>
    </header>

    <section class="ac-stats" aria-label="خلاصه ارتباطات">
        <div class="ac-stat"><strong>{{ number_format($stats['tickets_needing_reply']) }}</strong><span>تیکت نیازمند پاسخ</span></div>
        <div class="ac-stat"><strong>{{ number_format($stats['tickets_open']) }}</strong><span>تیکت باز</span></div>
        <div class="ac-stat"><strong>{{ number_format($stats['conversations_unread']) }}</strong><span>پیام خوانده‌نشده در گفت‌وگوها</span></div>
        <div class="ac-stat"><strong>{{ number_format($stats['notifications_unread']) }}</strong><span>اعلان خوانده‌نشده</span></div>
    </section>

    <section class="ac-panel">
        <h2><i class="fa-solid fa-clock-rotate-left"></i> جریان یکپارچه فعالیت‌ها</h2>
        <div class="ac-list">
            @forelse($feed as $item)
                <a class="ac-item" href="{{ $item['url'] }}">
                    <span class="ac-icon"><i class="fa-solid {{ $item['kind']==='ticket'?'fa-ticket':($item['kind']==='conversation'?'fa-comments':'fa-bell') }}"></i></span>
                    <span class="ac-copy"><strong>{{ $item['title'] }}</strong><small>{{ $item['body'] }}</small></span>
                    <span class="ac-meta">{{ $item['at']?->timezone(config('app.timezone'))->diffForHumans() }}</span>
                </a>
            @empty
                <div class="ac-empty">هنوز فعالیت ارتباطی برای نمایش وجود ندارد.</div>
            @endforelse
        </div>
    </section>

    <div class="ac-grid">
        <section class="ac-panel">
            <h2><i class="fa-solid fa-ticket"></i> تیکت‌های اخیر</h2>
            <div class="ac-list">
                @forelse($tickets as $ticket)
                    <a class="ac-item" href="{{ route('admin.support.management', ['ticket'=>$ticket->id]) }}">
                        <span class="ac-icon"><i class="fa-solid fa-headset"></i></span>
                        <span class="ac-copy"><strong>{{ $ticket->displayCode() }} · {{ $ticket->subject }}</strong><small>{{ $ticket->displayName() }} · {{ $ticket->statusLabel() }} · {{ $ticket->priorityLabel() }}</small></span>
                        <span class="ac-tag">{{ $ticket->departmentLabel() }}</span>
                    </a>
                @empty
                    <div class="ac-empty">تیکتی ثبت نشده است.</div>
                @endforelse
            </div>
            <div class="ac-footer"><span>نمایش آخرین ۱۰ تیکت</span><a href="{{ route('admin.support.management') }}">رفتن به میز پشتیبانی ←</a></div>
        </section>

        <div style="display:grid;gap:16px;align-content:start">
            <section class="ac-panel">
                <h2><i class="fa-solid fa-comments"></i> گفت‌وگوهای داخلی</h2>
                <div class="ac-list">
                    @forelse($conversations as $conversation)
                        <a class="ac-item" href="{{ route('messenger.show', $conversation) }}">
                            <span class="ac-icon"><i class="fa-regular fa-comment-dots"></i></span>
                            <span class="ac-copy"><strong>{{ $conversation->title ?: ($conversation->participants->firstWhere('id','!=',auth()->id())?->name ?? 'گفت‌وگوی خصوصی') }}</strong><small>{{ $conversation->last_message_preview ?: 'بدون پیام' }}</small></span>
                            @if($conversation->unread_messages_count)<span class="ac-tag">{{ $conversation->unread_messages_count }} جدید</span>@endif
                        </a>
                    @empty
                        <div class="ac-empty">گفت‌وگوی فعالی ندارید.</div>
                    @endforelse
                </div>
                <div class="ac-footer"><span>آخرین ۱۰ گفت‌وگو</span><a href="{{ route('messenger.index') }}">باز کردن پیام‌رسان ←</a></div>
            </section>

            <section class="ac-panel">
                <h2><i class="fa-regular fa-bell"></i> اعلان‌های اخیر</h2>
                <div class="ac-list">
                    @forelse($recentNotifications as $notification)
                        <a class="ac-item" href="{{ route('platform.notifications.visit', $notification->id) }}">
                            <span class="ac-icon"><i class="fa-solid {{ $notification->icon() }}"></i></span>
                            <span class="ac-copy"><strong>{{ $notification->title }}</strong><small>{{ $notification->body ?: $notification->categoryLabel() }}</small></span>
                            @unless($notification->isRead())<span class="ac-tag">جدید</span>@endunless
                        </a>
                    @empty
                        <div class="ac-empty">اعلانی وجود ندارد.</div>
                    @endforelse
                </div>
                <div class="ac-footer"><span>آخرین اعلان‌ها</span><a href="{{ route('platform.notifications') }}">مرکز اعلان‌ها ←</a></div>
            </section>
        </div>
    </div>
</div>
@endsection
