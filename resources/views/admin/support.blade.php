@extends('layouts.app')

@section('content')
@php
    $active = $active ?? ($tickets->getCollection()->first());
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018r">

<div class="rf-ticket-shell admin-desk" dir="rtl">
    <aside class="rf-ticket-list-pane">
        <div class="rf-list-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>پاسخ به تیکت کاربران و مهمان‌ها</p>
            </div>
        </div>

        <div class="rf-list-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو موضوع، ایمیل، شماره..." autocomplete="off">
        </div>

        <div class="rf-list-body" id="ticketList">
            @forelse($tickets as $t)
                <a href="{{ route('admin.support.management', ['ticket' => $t->id]) }}"
                   class="rf-ticket-row {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-subject="{{ e($t->subject.' '.$t->displayName().' '.($t->guest_email ?? '')) }}">
                    <div class="rf-ticket-row-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 42) }}</strong>
                        <span class="rf-badge rf-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="rf-ticket-row-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ $t->displayName() }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <div class="rf-empty">
                    <i class="fa-regular fa-inbox"></i>
                    <p>تیکتی برای پاسخ وجود ندارد</p>
                </div>
            @endforelse
        </div>

        @if($tickets->hasPages())
            <div style="padding:0.75rem 1rem;border-top:1px solid var(--rf-border)">{{ $tickets->withQueryString()->links() }}</div>
        @endif
    </aside>

    <section class="rf-ticket-chat-pane">
        @if($active)
            <header class="rf-chat-topbar">
                <div class="rf-chat-topbar-info">
                    <div class="rf-avatar"><i class="fa-solid fa-user"></i></div>
                    <div>
                        <h2>{{ $active->subject }}</h2>
                        <div class="rf-chat-topbar-meta">
                            <span class="rf-badge rf-badge-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>{{ $active->displayName() }}</span>
                            @if($active->guest_email)<span>{{ $active->guest_email }}</span>@endif
                            <span>#{{ $active->id }}</span>
                        </div>
                    </div>
                </div>
            </header>

            <div class="rf-messages" id="chatMessages">
                @foreach($active->messages as $m)
                    @php $isStaff = $m->isFromStaff(); @endphp
                    <div class="rf-msg {{ $isStaff ? 'rf-msg-user' : 'rf-msg-support' }}">
                        @unless($isStaff)
                            <div class="rf-msg-side">
                                <div class="rf-avatar rf-avatar-sm"><i class="fa-solid fa-user"></i></div>
                            </div>
                        @endunless
                        <div class="rf-msg-stack">
                            <div class="rf-bubble {{ $isStaff ? 'rf-bubble-user' : 'rf-bubble-support' }}">
                                <pre class="rf-bubble-text">{{ $m->body }}</pre>
                            </div>
                            <time class="rf-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isStaff ? 'پشتیبانی' : 'کاربر' }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            <form class="rf-composer" method="post" action="{{ route('admin.tickets.reply', $active) }}">
                @csrf
                <div class="rf-composer-bar" style="flex-wrap:wrap">
                    <div class="rf-composer-input" style="flex:1 1 100%">
                        <textarea name="body" rows="2" required maxlength="5000" placeholder="پاسخ پشتیبانی را بنویسید..."></textarea>
                    </div>
                    <div style="display:flex;gap:0.5rem;align-items:center;width:100%;justify-content:space-between;margin-top:0.35rem">
                        <select name="status" style="border:1px solid var(--rf-border);border-radius:10px;padding:0.45rem 0.7rem;font-family:inherit">
                            <option value="answered">پاسخ داده‌شده</option>
                            <option value="open">باز</option>
                            <option value="pending">در حال بررسی</option>
                            <option value="closed">بسته</option>
                        </select>
                        <button type="submit" class="rf-btn-primary">ارسال پاسخ</button>
                    </div>
                </div>
            </form>
        @else
            <div class="rf-placeholder">
                <div class="rf-placeholder-card">
                    <div class="rf-avatar rf-avatar-lg"><i class="fa-solid fa-inbox"></i></div>
                    <h2>میز پشتیبانی فراست</h2>
                    <p>یک تیکت از فهرست انتخاب کنید تا گفتگو و پاسخ نمایش داده شود.</p>
                </div>
            </div>
        @endif
    </section>
</div>

@if(session('status'))
    <div style="position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);background:#166534;color:#fff;padding:0.65rem 1.1rem;border-radius:12px;z-index:50;font-size:0.9rem">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018r"></script>
@endpush
@endsection
