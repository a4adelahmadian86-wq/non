@extends('layouts.app')

@section('content')
@php
    $active = $active ?? ($tickets->getCollection()->first());
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018pro">

<div class="sd-shell" dir="rtl">
    <aside class="sd-aside">
        <div class="sd-aside-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>پاسخ حرفه‌ای به تیکت کاربران و مهمان‌ها</p>
            </div>
        </div>

        <div class="sd-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="ticketSearch" placeholder="جستجو موضوع، نام، ایمیل، شماره..." autocomplete="off">
        </div>

        <div class="sd-filters" id="statusFilters">
            <button type="button" class="sd-chip is-on" data-status="all">همه</button>
            <button type="button" class="sd-chip" data-status="open">باز</button>
            <button type="button" class="sd-chip" data-status="answered">پاسخ‌داده‌شده</button>
            <button type="button" class="sd-chip" data-status="pending">در بررسی</button>
            <button type="button" class="sd-chip" data-status="closed">بسته</button>
        </div>

        <div class="sd-list" id="ticketList">
            @forelse($tickets as $t)
                @php $last = $t->messages->last(); @endphp
                <a href="{{ route('admin.support.management', ['ticket' => $t->id]) }}"
                   class="sd-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}"
                   data-subject="{{ e($t->subject.' '.$t->displayName().' '.($t->guest_email ?? '')) }}"
                   data-status="{{ $t->status }}">
                    <div class="sd-item-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 42) }}</strong>
                        <span class="sd-badge sd-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="sd-item-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ $t->displayName() }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                    </div>
                    @if($last)
                        <div class="sd-item-preview">{{ \Illuminate\Support\Str::limit($last->body, 55) }}</div>
                    @endif
                </a>
            @empty
                <div class="sd-empty">
                    <i class="fa-regular fa-inbox"></i>
                    <p>تیکتی در صف پاسخ نیست</p>
                </div>
            @endforelse
        </div>

        @if($tickets->hasPages())
            <div style="padding:12px 14px;border-top:1px solid var(--sd-line)">{{ $tickets->withQueryString()->links() }}</div>
        @endif
    </aside>

    <section class="sd-main">
        @if($active)
            <header class="sd-topbar">
                <div class="sd-topbar-info">
                    <div class="sd-avatar"><i class="fa-solid fa-user"></i></div>
                    <div style="min-width:0">
                        <h2>{{ $active->subject }}</h2>
                        <div class="sd-topbar-meta">
                            <span class="sd-badge sd-badge-{{ $active->statusColor() }}">{{ $active->statusLabel() }}</span>
                            <span>{{ $active->displayName() }}</span>
                            @if($active->guest_email)<span>{{ $active->guest_email }}</span>@endif
                            @if($active->user?->mobile)<span>{{ $active->user->mobile }}</span>@endif
                            <span>#{{ $active->id }}</span>
                        </div>
                    </div>
                </div>
            </header>

            <div class="sd-messages" id="chatMessages">
                @foreach($active->messages as $m)
                    @php $isStaff = $m->isFromStaff(); @endphp
                    <div class="sd-msg {{ $isStaff ? 'sd-msg-user' : 'sd-msg-staff' }}">
                        @unless($isStaff)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-user"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble"><pre>{{ $m->body }}</pre></div>
                            <time class="sd-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isStaff ? 'پشتیبانی' : 'کاربر' }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            <form class="sd-composer" method="post" action="{{ route('admin.tickets.reply', $active) }}">
                @csrf
                <div class="sd-composer-inner" style="flex-wrap:wrap">
                    <div class="sd-input-wrap" style="flex:1 1 100%">
                        <textarea name="body" rows="2" required maxlength="5000" placeholder="پاسخ حرفه‌ای خود را بنویسید..." dir="auto"></textarea>
                    </div>
                    <div style="display:flex;width:100%;gap:8px;align-items:center;justify-content:space-between;margin-top:4px">
                        <select name="status" style="border:1px solid var(--sd-line);border-radius:11px;padding:8px 12px;font:inherit;font-size:0.86rem">
                            <option value="answered">پاسخ داده‌شده</option>
                            <option value="open">باز نگه دار</option>
                            <option value="pending">در حال بررسی</option>
                            <option value="closed">بستن تیکت</option>
                        </select>
                        <button type="submit" class="sd-btn sd-btn-primary">ارسال پاسخ</button>
                    </div>
                </div>
            </form>
        @else
            <div class="sd-placeholder">
                <div class="sd-placeholder-card">
                    <div class="sd-avatar sd-avatar-lg"><i class="fa-solid fa-inbox"></i></div>
                    <h2>میز پشتیبانی فراست</h2>
                    <p>از فهرست سمت راست یک تیکت را انتخاب کنید و پاسخ دقیق، مودبانه و راه‌حل‌محور ارسال کنید.</p>
                </div>
            </div>
        @endif
    </section>
</div>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script src="{{ asset('js/support-messenger.js') }}?v=20261018pro"></script>
@endpush
@endsection
