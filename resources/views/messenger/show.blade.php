@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/messenger.css') }}?v=20261110">
<div class="fm-page" dir="rtl" id="fm-chat" data-poll-url="{{ route('messenger.poll', $conversation) }}" data-send-url="{{ route('messenger.send', $conversation) }}" data-last-id="{{ $messages->getCollection()->last()?->id ?? 0 }}" data-current-user-id="{{ auth()->id() }}" data-presence-url="{{ route('messenger.presence', $conversation) }}">
    <header class="fm-chat-header"><a href="{{ route('messenger.index') }}">← همه گفت‌وگوها</a><div><h1>{{ $conversation->title ?: ($conversation->participants->firstWhere('id', '!=', auth()->id())?->name ?? 'گفت‌وگوی خصوصی') }}</h1><p>{{ $conversation->participants->count() }} عضو <span id="fm-presence" aria-live="polite">• آنلاین</span></p></div>
        <form method="post" action="{{ route('messenger.archive', $conversation) }}">@csrf<button class="fm-secondary" type="submit">بایگانی گفتگو</button></form>
    </header>
    @if($conversation->type === 'group')
        <details class="fm-group-tools">
            <summary>اعضا و مدیریت گروه</summary>
            <div class="fm-group-members">@foreach($conversation->participants as $member)<span>{{ $member->name ?: $member->email }} @if($member->pivot->role === 'owner')<b>مدیر</b>@endif</span>@endforeach</div>
            @if($isGroupOwner)
                <form method="post" action="{{ route('messenger.update', $conversation) }}" class="fm-inline-form">@csrf @method('PATCH')<label>عنوان گروه<input name="title" maxlength="160" value="{{ $conversation->title }}" required></label><button class="fm-secondary" type="submit">ذخیره عنوان</button></form>
                @if($availableUsers->isNotEmpty())
                    <form method="post" action="{{ route('messenger.participants.add', $conversation) }}" class="fm-inline-form">@csrf<label>افزودن عضو<select name="participant_ids[]" multiple required size="4">@foreach($availableUsers as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name ?: $candidate->email }}</option>@endforeach</select></label><button class="fm-secondary" type="submit">افزودن به گروه</button></form>
                @endif
            @endif
            <form method="post" action="{{ route('messenger.leave', $conversation) }}" class="fm-inline-form">@csrf @method('DELETE')
                @if($isGroupOwner && $conversation->participants->where('id', '!=', auth()->id())->isNotEmpty())
                    <label>واگذاری مدیریت به<select name="new_owner_id" required><option value="">انتخاب عضو جدید</option>@foreach($conversation->participants->where('id', '!=', auth()->id()) as $member)<option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>@endforeach</select></label>
                @endif
                <button class="fm-secondary" type="submit">خروج از گروه</button>
            </form>
        </details>
    @endif
    <main class="fm-chat-panel">
        <div class="fm-messages" id="fm-messages">
            @foreach($messages as $message)
                <article class="fm-message {{ (int) $message->user_id === (int) auth()->id() ? 'is-mine' : '' }}" data-message-id="{{ $message->id }}">
                    <div class="fm-message-meta"><strong>{{ $message->user?->name ?? 'کاربر حذف‌شده' }}</strong><time>{{ $message->created_at?->format('Y/m/d H:i') }}</time></div>
                    @if($message->body !== '')<p>{{ $message->body }}</p>@endif
                    @foreach($message->attachments ?? [] as $i => $attachment)
                        <a class="fm-attachment" href="{{ route('messenger.attachment', [$conversation, $message, $i]) }}">📎 {{ $attachment['name'] ?? 'پیوست' }}</a>
                    @endforeach
                </article>
            @endforeach
        </div>
        <form class="fm-composer" id="fm-composer" enctype="multipart/form-data">
            @csrf
            <textarea name="body" rows="3" maxlength="10000" placeholder="پیام خود را بنویسید…" aria-label="متن پیام"></textarea>
            <div class="fm-composer-actions"><label class="fm-file">افزودن فایل<input type="file" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.doc,.docx,.xls,.xlsx,.zip"></label><small>حداکثر ۵ فایل، هرکدام ۱۰ مگابایت</small><button class="fm-primary" type="submit">ارسال پیام</button></div>
            <p class="fm-error" id="fm-error" role="alert"></p>
        </form>
    </main>
</div>
<script src="{{ asset('js/messenger.js') }}?v=20261110" defer></script>
@endsection
