@extends('layouts.app')

@section('content')
@php
    $activeId = (int) request('ticket');
    $active = $tickets->getCollection()->firstWhere('id', $activeId) ?? $tickets->getCollection()->first();
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018c">

<div class="support-messenger admin-desk" dir="rtl">
    <aside class="support-sidebar">
        <div class="support-sidebar-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>پاسخ به تیکت کاربران و مهمان‌ها</p>
            </div>
        </div>

        <div class="support-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو موضوع، ایمیل، شماره..." autocomplete="off">
        </div>

        <div class="ticket-list" id="ticketList">
            @forelse($tickets as $t)
                <a href="{{ route('admin.support.management', ['ticket' => $t->id]) }}"
                   class="ticket-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-id="{{ $t->id }}"
                   data-subject="{{ e($t->subject.' '.$t->displayName().' '.($t->guest_email ?? '')) }}">
                    <div class="ticket-item-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 42) }}</strong>
                        <span class="status-dot status-{{ $t->statusColor() }}" title="{{ $t->statusLabel() }}"></span>
                    </div>
                    <div class="ticket-item-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ $t->displayName() }}</span>
                        <span>{{ $t->statusLabel() }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <div class="ticket-empty">
                    <i class="fa-regular fa-inbox"></i>
                    <p>تیکتی برای پاسخ وجود ندارد</p>
                </div>
            @endforelse
        </div>

        @if($tickets->hasPages())
            <div style="padding:0.75rem 1rem;border-top:1px solid var(--sm-border)">{{ $tickets->withQueryString()->links() }}</div>
        @endif
    </aside>

    <section class="support-chat">
        @if($active)
            <header class="chat-header">
                <div class="chat-header-info">
                    <div class="avatar staff"><i class="fa-solid fa-user"></i></div>
                    <div>
                        <h2>{{ $active->subject }}</h2>
                        <p>
                            <span class="status-pill status-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>{{ $active->displayName() }}</span>
                            @if($active->guest_email)<span>{{ $active->guest_email }}</span>@endif
                            <span>#{{ $active->id }}</span>
                        </p>
                    </div>
                </div>
            </header>

            <div class="chat-messages" id="chatMessages">
                @foreach($active->messages as $m)
                    @php $isStaff = $m->isFromStaff(); @endphp
                    <div class="msg-row {{ $isStaff ? 'mine' : 'staff' }}">
                        @unless($isStaff)
                            <div class="msg-avatar"><i class="fa-solid fa-user"></i></div>
                        @endunless
                        <div class="msg-bubble">
                            <div class="msg-body">{!! nl2br(e($m->body)) !!}</div>
                            <time>{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isStaff ? 'پشتیبانی' : 'کاربر' }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            <form class="chat-composer admin-reply" method="post" action="{{ route('admin.tickets.reply', $active) }}" style="flex-wrap:wrap">
                @csrf
                <div class="composer-input-wrap" style="flex:1 1 100%">
                    <textarea name="body" rows="2" required maxlength="5000" placeholder="پاسخ پشتیبانی را بنویسید..."></textarea>
                </div>
                <div style="display:flex;gap:0.5rem;align-items:center;width:100%;justify-content:space-between">
                    <select name="status" style="border:1px solid var(--sm-border);border-radius:10px;padding:0.45rem 0.7rem;font-family:inherit">
                        <option value="answered">پاسخ داده‌شده</option>
                        <option value="open">باز</option>
                        <option value="pending">در حال بررسی</option>
                        <option value="closed">بسته</option>
                    </select>
                    <button type="submit" class="btn primary">ارسال پاسخ</button>
                </div>
            </form>
        @else
            <div class="chat-placeholder">
                <div class="placeholder-card">
                    <i class="fa-solid fa-inbox"></i>
                    <h2>میز پشتیبانی فراست</h2>
                    <p>یک تیکت از فهرست سمت راست انتخاب کنید تا گفتگو و پاسخ نمایش داده شود.</p>
                </div>
            </div>
        @endif
    </section>
</div>

@if(session('status'))
    <div style="position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);background:#166534;color:#fff;padding:0.65rem 1.1rem;border-radius:12px;z-index:50;font-size:0.9rem">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018c"></script>
@endpush
@endsection
