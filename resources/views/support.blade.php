@extends('layouts.app')

@section('content')
@php
    $activeId = $activeTicketId ?? ($tickets->first()?->id);
    $active = $tickets->firstWhere('id', (int) $activeId) ?? $tickets->first();
@endphp

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018b">

<div class="support-messenger" dir="rtl">
    <aside class="support-sidebar">
        <div class="support-sidebar-head">
            <div>
                <h1>پشتیبانی</h1>
                <p>گفتگوهای شما با تیم فراست</p>
            </div>
            <button type="button" class="btn-new-ticket" id="openNewTicket" title="تیکت جدید">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

        <div class="support-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو در تیکت‌ها..." autocomplete="off">
        </div>

        <div class="ticket-list" id="ticketList">
            @forelse($tickets as $t)
                <a href="{{ route('support', ['ticket' => $t->id]) }}"
                   class="ticket-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-id="{{ $t->id }}"
                   data-subject="{{ e($t->subject) }}">
                    <div class="ticket-item-top">
                        <strong>{{ Str::limit($t->subject, 40) }}</strong>
                        <span class="status-dot status-{{ $t->statusColor() }}" title="{{ $t->statusLabel() }}"></span>
                    </div>
                    <div class="ticket-item-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ $t->statusLabel() }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <div class="ticket-empty">
                    <i class="fa-regular fa-comments"></i>
                    <p>هنوز تیکتی ندارید</p>
                    <button type="button" class="btn primary" id="openNewTicketEmpty">شروع گفتگو</button>
                </div>
            @endforelse
        </div>
    </aside>

    <section class="support-chat">
        @if($active)
            <header class="chat-header">
                <div class="chat-header-info">
                    <div class="avatar staff"><i class="fa-solid fa-headset"></i></div>
                    <div>
                        <h2>{{ $active->subject }}</h2>
                        <p>
                            <span class="status-pill status-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>شماره تیکت {{ $active->id }}</span>
                        </p>
                    </div>
                </div>
                @if($active->status !== 'closed')
                    <form method="post" action="{{ route('support.close', $active) }}" onsubmit="return confirm('آیا از بستن این تیکت مطمئن هستید؟')">
                        @csrf
                        <button type="submit" class="btn-ghost">بستن تیکت</button>
                    </form>
                @endif
            </header>

            <div class="chat-messages" id="chatMessages" data-ticket-id="{{ $active->id }}">
                @foreach($active->messages as $m)
                    @php $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff(); @endphp
                    <div class="msg-row {{ $isMine ? 'mine' : 'staff' }}">
                        @unless($isMine)
                            <div class="msg-avatar"><i class="fa-solid fa-headset"></i></div>
                        @endunless
                        <div class="msg-bubble">
                            <div class="msg-body">{!! nl2br(e($m->body)) !!}</div>
                            <time>{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($active->status !== 'closed')
                <form class="chat-composer" id="chatComposer" method="post" action="{{ route('support.message', $active) }}">
                    @csrf
                    <div class="composer-tools">
                        <button type="button" class="tool-btn" title="ایموجی" id="emojiToggle"><i class="fa-regular fa-face-smile"></i></button>
                        <label class="tool-btn" title="پیوست فایل">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt" hidden>
                        </label>
                    </div>
                    <div class="composer-input-wrap">
                        <textarea name="body" id="composerBody" rows="1" placeholder="پیام بنویسید..." required maxlength="5000"></textarea>
                    </div>
                    <button type="submit" class="send-btn" title="ارسال"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
            @else
                <div class="chat-closed-banner">این تیکت بسته شده است. برای موضوع جدید، تیکت تازه باز کنید.</div>
            @endif
        @else
            <div class="chat-placeholder">
                <div class="placeholder-card">
                    <i class="fa-solid fa-headset"></i>
                    <h2>به پشتیبانی فراست خوش آمدید</h2>
                    <p>سؤالی دارید؟ تیکت جدید بسازید یا از لیست یک گفتگو را انتخاب کنید.</p>
                    <button type="button" class="btn primary" id="openNewTicketMain">تیکت جدید</button>
                </div>
            </div>
        @endif
    </section>
</div>

<dialog id="newTicketModal" class="support-modal">
    <form method="post" action="{{ route('support.create') }}" class="modal-card">
        @csrf
        <header>
            <h3>تیکت جدید</h3>
            <button type="button" class="modal-close" data-close>&times;</button>
        </header>
        <div class="modal-body">
            <label>دپارتمان
                <select name="department">
                    <option value="support">پشتیبانی فنی</option>
                    <option value="sales">فروش</option>
                    <option value="billing">حسابداری</option>
                    <option value="complaint">شکایات</option>
                </select>
            </label>
            <label>اولویت
                <select name="priority">
                    <option value="low">کم</option>
                    <option value="normal" selected>متوسط</option>
                    <option value="high">زیاد</option>
                </select>
            </label>
            <label>عنوان
                <input type="text" name="subject" required maxlength="160" placeholder="موضوع تیکت را بنویسید">
            </label>
            <label>شرح پیام
                <textarea name="body" required maxlength="5000" rows="5" placeholder="مشکل یا سؤال خود را کامل توضیح دهید..."></textarea>
            </label>
        </div>
        <footer>
            <button type="button" class="btn-ghost" data-close>انصراف</button>
            <button type="submit" class="btn primary">ثبت تیکت</button>
        </footer>
    </form>
</dialog>

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018b"></script>
@endpush
@endsection
