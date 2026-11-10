@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/messenger.css') }}?v=20261110">
<div class="fm-page" dir="rtl" id="fm-chat" data-poll-url="{{ route('messenger.poll', $conversation) }}" data-send-url="{{ route('messenger.send', $conversation) }}" data-last-id="{{ $messages->getCollection()->last()?->id ?? 0 }}" data-current-user-id="{{ auth()->id() }}">
    <header class="fm-chat-header"><a href="{{ route('messenger.index') }}">← همه گفت‌وگوها</a><div><h1>{{ $conversation->title ?: ($conversation->participants->firstWhere('id', '!=', auth()->id())?->name ?? 'گفت‌وگوی خصوصی') }}</h1><p>{{ $conversation->participants->count() }} عضو</p></div></header>
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
