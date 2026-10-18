@extends('layouts.app')

@section('content')
@php
    $activeId = $activeTicketId ?? ($tickets->first()?->id);
    $active = $tickets->firstWhere('id', (int) $activeId) ?? $tickets->first();
@endphp

<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018pro">

<div class="sd-shell" dir="rtl">
    <aside class="sd-aside">
        <div class="sd-aside-head">
            <div>
                <h1>پشتیبانی</h1>
                <p>گفتگو و پیگیری تیکت‌های شما</p>
            </div>
            <button type="button" class="sd-icon-btn" id="openNewTicket" title="تیکت جدید">
                <i class="fa-solid fa-plus"></i>
            </button>
        </div>

        <div class="sd-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو در موضوع تیکت..." autocomplete="off">
        </div>

        <div class="sd-filters" id="statusFilters">
            <button type="button" class="sd-chip is-on" data-status="all">همه</button>
            <button type="button" class="sd-chip" data-status="open">باز</button>
            <button type="button" class="sd-chip" data-status="answered">پاسخ‌داده‌شده</button>
            <button type="button" class="sd-chip" data-status="closed">بسته</button>
        </div>

        <div class="sd-list" id="ticketList">
            @forelse($tickets as $t)
                @php $last = $t->messages->last(); @endphp
                <a href="{{ route('support', ['ticket' => $t->id]) }}"
                   class="sd-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-subject="{{ e($t->subject) }}"
                   data-status="{{ $t->status }}">
                    <div class="sd-item-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 48) }}</strong>
                        <span class="sd-badge sd-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="sd-item-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                    @if($last)
                        <div class="sd-item-preview">{{ \Illuminate\Support\Str::limit($last->body, 60) }}</div>
                    @endif
                </a>
            @empty
                <div class="sd-empty">
                    <i class="fa-regular fa-comments"></i>
                    <p>هنوز تیکتی ندارید</p>
                    <button type="button" class="sd-btn sd-btn-primary" id="openNewTicketEmpty" style="margin-top:12px">شروع گفتگو</button>
                </div>
            @endforelse
        </div>
    </aside>

    <section class="sd-main">
        @if($active)
            <header class="sd-topbar">
                <div class="sd-topbar-info">
                    <div class="sd-avatar"><i class="fa-solid fa-headset"></i></div>
                    <div style="min-width:0">
                        <h2>{{ $active->subject }}</h2>
                        <div class="sd-topbar-meta">
                            <span class="sd-badge sd-badge-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>شماره تیکت {{ $active->id }}</span>
                            @if($active->department ?? null)
                                <span>· {{ match($active->department) { 'sales' => 'فروش', 'billing' => 'مالی', 'complaint' => 'شکایات', default => 'پشتیبانی فنی' } }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                @if($active->status !== 'closed')
                    <button type="button" class="sd-btn sd-btn-danger" id="btnCloseTicketOpen">بستن تیکت</button>
                @endif
            </header>

            <div class="sd-messages" id="chatMessages" data-ticket-id="{{ $active->id }}">
                @foreach($active->messages as $m)
                    @php $isMine = $m->user_id === auth()->id() && ! $m->isFromStaff(); @endphp
                    <div class="sd-msg {{ $isMine ? 'sd-msg-user' : 'sd-msg-staff' }}">
                        @unless($isMine)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-headset"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble">
                                <pre>{{ $m->body }}</pre>
                                @if(!empty($m->attachments))
                                    @foreach((array) $m->attachments as $att)
                                        <a class="sd-attach" href="{{ asset('storage/'.($att['path'] ?? '')) }}" target="_blank" rel="noopener">
                                            <i class="fa-solid fa-paperclip"></i>
                                            {{ $att['name'] ?? 'پیوست' }}
                                        </a>
                                    @endforeach
                                @endif
                            </div>
                            <time class="sd-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($active->status !== 'closed')
                <form class="sd-composer" id="chatComposer" method="post" action="{{ route('support.message', $active) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="sd-composer-inner">
                        <div class="sd-tools">
                            <button type="button" class="sd-tool" id="emojiToggle" title="ایموجی"><i class="fa-regular fa-face-smile"></i></button>
                            <label class="sd-tool" title="پیوست">
                                <i class="fa-solid fa-paperclip"></i>
                                <input type="file" name="attachment" id="composerFile" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip" hidden>
                            </label>
                        </div>
                        <div class="sd-input-wrap">
                            <textarea name="body" id="composerBody" rows="1" required maxlength="5000" placeholder="متن پاسخ شما ..." dir="auto"></textarea>
                        </div>
                        <button type="submit" class="sd-send" title="ارسال" id="sendBtn">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        </button>
                    </div>
                    <span class="sd-file-chip" id="composerFileName" hidden></span>
                    <div class="sd-emoji-panel" id="emojiPanel" hidden>
                        @foreach(['😀','😁','😂','😊','😍','🤔','👍','👎','🙏','🔥','✨','✅','❌','❤️','💙','🎉','📌','💡','😅','😢','🤝','👏','💪','🚀','⭐','⚡','🛡️','📎','📝','🔔'] as $e)
                            <button type="button" data-emoji="{{ $e }}">{{ $e }}</button>
                        @endforeach
                    </div>
                </form>
            @else
                <div class="sd-closed">این تیکت بسته شده است. برای موضوع جدید، تیکت تازه ثبت کنید.</div>
            @endif

            <dialog id="closeTicketDialog" class="sd-dialog">
                <form method="post" action="{{ route('support.close', $active) }}" class="sd-dialog-card">
                    @csrf
                    <h3>بستن تیکت</h3>
                    <p>آیا از بستن این تیکت مطمئن هستید؟ پس از بستن امکان ارسال پیام وجود ندارد.</p>
                    <div class="sd-dialog-actions">
                        <button type="button" class="sd-btn sd-btn-ghost" data-close-dialog>انصراف</button>
                        <button type="submit" class="sd-btn sd-btn-primary">بله، بستن تیکت</button>
                    </div>
                </form>
            </dialog>
        @else
            <div class="sd-placeholder">
                <div class="sd-placeholder-card">
                    <div class="sd-avatar sd-avatar-lg"><i class="fa-solid fa-headset"></i></div>
                    <h2>به پشتیبانی فراست خوش آمدید</h2>
                    <p>سؤال یا مشکلی دارید؟ تیکت جدید بسازید یا از فهرست یک گفتگوی قبلی را باز کنید. تیم ما در کوتاه‌ترین زمان پاسخ می‌دهد.</p>
                    <button type="button" class="sd-btn sd-btn-primary" id="openNewTicketMain">ثبت تیکت جدید</button>
                </div>
            </div>
        @endif
    </section>
</div>

<dialog id="newTicketModal" class="sd-dialog">
    <form method="post" action="{{ route('support.create') }}" class="sd-dialog-card">
        @csrf
        <div class="sd-dialog-head">
            <h3>ثبت تیکت جدید</h3>
            <button type="button" class="sd-dialog-x" data-close>&times;</button>
        </div>
        <div class="sd-dialog-body">
            <label class="sd-field">دپارتمان
                <select name="department">
                    <option value="support">پشتیبانی فنی</option>
                    <option value="sales">فروش</option>
                    <option value="billing">حسابداری / مالی</option>
                    <option value="complaint">شکایات</option>
                </select>
            </label>
            <label class="sd-field">اولویت
                <select name="priority">
                    <option value="low">کم</option>
                    <option value="normal" selected>متوسط</option>
                    <option value="high">زیاد</option>
                </select>
            </label>
            <label class="sd-field">عنوان
                <input type="text" name="subject" required maxlength="160" placeholder="موضوع تیکت را بنویسید">
            </label>
            <label class="sd-field">شرح پیام
                <textarea name="body" required maxlength="5000" rows="5" placeholder="مشکل یا سؤال خود را کامل توضیح دهید..."></textarea>
            </label>
        </div>
        <div class="sd-dialog-actions">
            <button type="button" class="sd-btn sd-btn-ghost" data-close>انصراف</button>
            <button type="submit" class="sd-btn sd-btn-primary">ثبت تیکت</button>
        </div>
    </form>
</dialog>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018pro"></script>
@endpush
@endsection
