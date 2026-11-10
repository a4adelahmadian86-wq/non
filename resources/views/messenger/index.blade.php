@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/messenger.css') }}?v=20261110">
<div class="fm-page" dir="rtl">
    <header class="fm-header">
        <div><span class="fm-eyebrow">FARAST • COMMUNICATIONS</span><h1>مرکز پیام‌رسانی</h1><p>گفت‌وگوهای خصوصی و گروهی در فضای امن داخلی فراست</p></div>
        <button class="fm-primary" type="button" data-open-compose>گفت‌وگوی جدید</button>
    </header>
    <section class="fm-layout">
        <div class="fm-list">
            <div class="fm-list-head"><strong>گفت‌وگوها</strong><span>{{ $conversations->total() }} مورد</span></div>
            @forelse($conversations as $conversation)
                <a class="fm-conversation" href="{{ route('messenger.show', $conversation) }}">
                    <span class="fm-avatar">{{ mb_substr($conversation->title ?: ($conversation->participants->firstWhere('id', '!=', auth()->id())?->name ?? 'گفتگو'), 0, 1) }}</span>
                    <span class="fm-conversation-copy"><strong>{{ $conversation->title ?: ($conversation->participants->firstWhere('id', '!=', auth()->id())?->name ?? 'گفت‌وگوی خصوصی') }}</strong><small>{{ $conversation->last_message_preview ?: 'هنوز پیامی ارسال نشده است' }}</small></span>
                    @if(($conversation->unread_messages_count ?? 0) > 0)<b class="fm-unread">{{ $conversation->unread_messages_count }}</b>@endif
                </a>
            @empty
                <div class="fm-empty"><strong>هنوز گفت‌وگویی ندارید</strong><p>از دکمه گفت‌وگوی جدید برای شروع پیام‌رسانی استفاده کنید.</p></div>
            @endforelse
            <div class="fm-pagination">{{ $conversations->links() }}</div>
        </div>
        <aside class="fm-welcome"><div class="fm-welcome-icon">✉</div><h2>ارتباطات، یکپارچه و درون‌سازمانی</h2><p>گفت‌وگوهای خصوصی و گروهی، بدون وابستگی به پیام‌رسان خارجی و با کنترل دسترسی در سطح اعضا.</p><ul><li>گفت‌وگوی مستقیم و گروهی</li><li>تاریخچه و وضعیت خوانده‌شدن</li><li>پیوست خصوصی و کنترل‌شده</li></ul></aside>
    </section>
</div>
<dialog class="fm-dialog" data-compose>
    <form method="post" action="{{ route('messenger.store') }}">
        @csrf
        <div class="fm-dialog-head"><h2>شروع گفت‌وگو</h2><button type="button" data-close-compose aria-label="بستن">×</button></div>
        <label>نوع گفت‌وگو<select name="type" data-conversation-type><option value="direct">خصوصی</option><option value="group">گروهی</option></select></label>
        <label data-group-title hidden>عنوان گروه<input name="title" maxlength="160" placeholder="مثلاً تیم فروش"></label>
        <label>انتخاب مخاطبان<select name="participant_ids[]" multiple required size="8">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name ?: $user->email }} — {{ $user->email }}</option>@endforeach</select></label>
        <p class="fm-hint">برای انتخاب چند نفر، کلید Ctrl یا Command را نگه دارید.</p>
        <button class="fm-primary" type="submit">ایجاد گفت‌وگو</button>
    </form>
</dialog>
<script src="{{ asset('js/messenger.js') }}?v=20261110" defer></script>
@endsection
