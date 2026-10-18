@extends('layouts.app')

@section('content')
@php
    $activeId = $activeTicketId ?? ($tickets->first()?->id);
    $active = $tickets->firstWhere('id', (int) $activeId) ?? $tickets->first();
@endphp

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018r">

<div class="rf-ticket-shell" dir="rtl">
    {{-- ستون لیست تیکت‌ها (مشابه لیست ران‌فلر) --}}
    <aside class="rf-ticket-list-pane">
        <div class="rf-list-head">
            <div>
                <h1>تیکت‌های پشتیبانی</h1>
                <p>تاریخچه گفتگو با پشتیبانی فراست</p>
            </div>
            <button type="button" class="rf-btn-primary rf-btn-icon" id="openNewTicket" title="تیکت جدید">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

        <div class="rf-list-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو در موضوع تیکت..." autocomplete="off">
        </div>

        <div class="rf-list-body" id="ticketList">
            @forelse($tickets as $t)
                <a href="{{ route('support', ['ticket' => $t->id]) }}"
                   class="rf-ticket-row {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-subject="{{ e($t->subject) }}">
                    <div class="rf-ticket-row-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 48) }}</strong>
                        <span class="rf-badge rf-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="rf-ticket-row-meta">
                        <span>شماره {{ $t->id }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <div class="rf-empty">
                    <i class="fa-regular fa-comments"></i>
                    <p>هنوز تیکتی ثبت نکرده‌اید</p>
                    <button type="button" class="rf-btn-primary" id="openNewTicketEmpty">ایجاد تیکت</button>
                </div>
            @endforelse
        </div>
    </aside>

    {{-- پنل گفتگو (مشابه صفحه جزئیات تیکت ران‌فلر) --}}
    <section class="rf-ticket-chat-pane">
        @if($active)
            <header class="rf-chat-topbar">
                <div class="rf-chat-topbar-info">
                    <div class="rf-avatar rf-avatar-support"><i class="fa-solid fa-headset"></i></div>
                    <div>
                        <h2>{{ $active->subject }}</h2>
                        <div class="rf-chat-topbar-meta">
                            <span class="rf-badge rf-badge-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>تیکت #{{ $active->id }}</span>
                        </div>
                    </div>
                </div>
                @if($active->status !== 'closed')
                    <button type="button" class="rf-btn-outline" id="btnCloseTicketOpen" data-dialog="closeTicketDialog">بستن تیکت</button>
                @endif
            </header>

            <div class="rf-messages" id="chatMessages">
                @foreach($active->messages as $m)
                    @php
                        $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff();
                    @endphp
                    {{-- حباب پیام دقیقاً در سبک نمونه: کاربر primary، پشتیبانی کارت حاشیه‌دار --}}
                    <div class="rf-msg {{ $isMine ? 'rf-msg-user' : 'rf-msg-support' }}">
                        @unless($isMine)
                            <div class="rf-msg-side">
                                <div class="rf-avatar rf-avatar-sm"><i class="fa-solid fa-headset"></i></div>
                            </div>
                        @endunless
                        <div class="rf-msg-stack">
                            <div class="rf-bubble {{ $isMine ? 'rf-bubble-user' : 'rf-bubble-support' }}">
                                <pre class="rf-bubble-text">{{ $m->body }}</pre>
                            </div>
                            <time class="rf-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($active->status !== 'closed')
                {{-- کامپوزر شبیه روبیکا: ابزار + ورودی + ارسال --}}
                <form class="rf-composer" id="chatComposer" method="post" action="{{ route('support.message', $active) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="rf-composer-bar">
                        <div class="rf-composer-tools">
                            <button type="button" class="rf-tool" id="emojiToggle" title="ایموجی" aria-label="ایموجی">
                                <i class="fa-regular fa-face-smile"></i>
                            </button>
                            <label class="rf-tool" title="پیوست فایل">
                                <i class="fa-solid fa-paperclip"></i>
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip" hidden id="composerFile">
                            </label>
                            <span class="rf-file-name" id="composerFileName" hidden></span>
                        </div>

                        <div class="rf-composer-input">
                            <textarea name="body" id="composerBody" rows="1" required maxlength="5000"
                                      placeholder="متن پاسخ شما ..." dir="auto"></textarea>
                        </div>

                        <button type="submit" class="rf-send" title="ارسال" aria-label="ارسال">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>

                    <div class="rf-emoji-panel" id="emojiPanel" hidden>
                        @foreach(['😀','😁','😂','🤣','😊','😍','🤔','👍','👎','🙏','🔥','✨','✅','❌','❤️','💙','🎉','📌','📎','💡','😅','😢','😡','🤝','👏','💪','🌟','⚡','🛡️','🚀'] as $e)
                            <button type="button" class="rf-emoji-item" data-emoji="{{ $e }}">{{ $e }}</button>
                        @endforeach
                    </div>
                </form>
            @else
                <div class="rf-closed-banner">این تیکت بسته شده است. برای موضوع جدید یک تیکت تازه باز کنید.</div>
            @endif

            {{-- دیالوگ تأیید بستن تیکت (مشابه نمونه) --}}
            <dialog id="closeTicketDialog" class="rf-dialog">
                <form method="post" action="{{ route('support.close', $active) }}" class="rf-dialog-card">
                    @csrf
                    <h3>بستن تیکت</h3>
                    <p>آیا از بستن این تیکت مطمئن هستید؟ پس از بستن امکان ارسال پیام وجود ندارد.</p>
                    <div class="rf-dialog-actions">
                        <button type="button" class="rf-btn-outline" data-close-dialog>انصراف</button>
                        <button type="submit" class="rf-btn-primary">بله، بستن تیکت</button>
                    </div>
                </form>
            </dialog>
        @else
            <div class="rf-placeholder">
                <div class="rf-placeholder-card">
                    <div class="rf-avatar rf-avatar-lg"><i class="fa-solid fa-headset"></i></div>
                    <h2>پشتیبانی فراست</h2>
                    <p>برای شروع، تیکت جدید بسازید یا از فهرست سمت راست یک گفتگو را انتخاب کنید.</p>
                    <button type="button" class="rf-btn-primary" id="openNewTicketMain">ثبت تیکت جدید</button>
                </div>
            </div>
        @endif
    </section>
</div>

{{-- مودال ساخت تیکت (فیلدهای نمونه ران‌فلر: دپارتمان، اولویت، عنوان، شرح) --}}
<dialog id="newTicketModal" class="rf-dialog">
    <form method="post" action="{{ route('support.create') }}" class="rf-dialog-card rf-dialog-wide">
        @csrf
        <div class="rf-dialog-head">
            <h3>ثبت تیکت جدید</h3>
            <button type="button" class="rf-dialog-x" data-close>&times;</button>
        </div>
        <div class="rf-dialog-body">
            <label class="rf-field">
                <span>دپارتمان</span>
                <select name="department">
                    <option value="support">پشتیبانی فنی</option>
                    <option value="sales">فروش</option>
                    <option value="billing">حسابداری / مالی</option>
                    <option value="complaint">شکایات</option>
                </select>
            </label>
            <label class="rf-field">
                <span>اولویت</span>
                <select name="priority">
                    <option value="low">کم</option>
                    <option value="normal" selected>متوسط</option>
                    <option value="high">زیاد</option>
                </select>
            </label>
            <label class="rf-field">
                <span>عنوان</span>
                <input type="text" name="subject" required maxlength="160" placeholder="موضوع تیکت را بنویسید">
            </label>
            <label class="rf-field">
                <span>شرح پیام</span>
                <textarea name="body" required maxlength="5000" rows="5" placeholder="مشکل یا سؤال خود را کامل توضیح دهید..."></textarea>
            </label>
        </div>
        <div class="rf-dialog-actions">
            <button type="button" class="rf-btn-outline" data-close>انصراف</button>
            <button type="submit" class="rf-btn-primary">ثبت تیکت</button>
        </div>
    </form>
</dialog>

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018r"></script>
@endpush
@endsection
