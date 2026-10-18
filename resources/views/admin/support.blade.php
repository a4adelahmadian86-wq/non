@extends('layouts.app')

@section('content')
@php
    $active = $active ?? ($tickets->getCollection()->first());
    $stats = $stats ?? ['open'=>0,'answered'=>0,'pending'=>0,'closed'=>0];
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261018pro2">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261018pro2">

<div class="sd-shell" dir="rtl">
    <aside class="sd-aside">
        <div class="sd-aside-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>پاسخ حرفه‌ای به تیکت کاربران و مهمان‌ها</p>
            </div>
        </div>

        <div class="sd-admin-stats">
            <div class="sd-admin-stat"><b>{{ $stats['open'] }}</b><span>نیازمند پاسخ</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['answered'] }}</b><span>پاسخ‌داده‌شده</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['pending'] }}</b><span>در بررسی</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['closed'] }}</b><span>بسته</span></div>
        </div>

        <div class="sd-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <form method="get" action="{{ route('admin.support.management') }}" style="width:100%">
                @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                <input type="search" name="q" value="{{ request('q') }}" placeholder="جستجو موضوع، ایمیل، شماره..." autocomplete="off" onchange="this.form.submit()">
            </form>
        </div>

        <div class="sd-filters">
            @php $qs = array_filter(['q'=>request('q')]); @endphp
            <a class="sd-chip {{ !request('status')||request('status')==='all'?'is-on':'' }}" href="{{ route('admin.support.management', $qs) }}" style="text-decoration:none">همه</a>
            <a class="sd-chip {{ request('status')==='open'?'is-on':'' }}" href="{{ route('admin.support.management', $qs+['status'=>'open']) }}" style="text-decoration:none">باز</a>
            <a class="sd-chip {{ request('status')==='user_replied'?'is-on':'' }}" href="{{ route('admin.support.management', $qs+['status'=>'user_replied']) }}" style="text-decoration:none">پاسخ کاربر</a>
            <a class="sd-chip {{ request('status')==='answered'?'is-on':'' }}" href="{{ route('admin.support.management', $qs+['status'=>'answered']) }}" style="text-decoration:none">پاسخ‌شده</a>
            <a class="sd-chip {{ request('status')==='pending'?'is-on':'' }}" href="{{ route('admin.support.management', $qs+['status'=>'pending']) }}" style="text-decoration:none">بررسی</a>
            <a class="sd-chip {{ request('status')==='closed'?'is-on':'' }}" href="{{ route('admin.support.management', $qs+['status'=>'closed']) }}" style="text-decoration:none">بسته</a>
        </div>

        <div class="sd-list" id="ticketList">
            @forelse($tickets as $t)
                @php $last = $t->messages->last(); @endphp
                <a href="{{ route('admin.support.management', array_filter(['ticket'=>$t->id,'status'=>request('status'),'q'=>request('q')])) }}"
                   class="sd-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}">
                    <div class="sd-item-top">
                        <strong>{{ \Illuminate\Support\Str::limit($t->subject, 42) }}</strong>
                        <span class="sd-badge sd-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="sd-item-meta">
                        <span>#{{ $t->id }}</span>
                        <span>{{ $t->displayName() }}</span>
                        <span>{{ $t->departmentLabel() }}</span>
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
                            @if($active->user?->email)<span>{{ $active->user->email }}</span>@endif
                            <span>{{ $active->departmentLabel() }}</span>
                            <span>اولویت: {{ $active->priorityLabel() }}</span>
                            <span>#{{ $active->id }}</span>
                        </div>
                    </div>
                </div>
            </header>

            @if($active->topicLabel() || ($active->relatedProduct ?? null) || $active->serviceLabel())
            <div style="padding:10px 18px;background:#f8fafc;border-bottom:1px solid var(--sd-line);display:flex;flex-wrap:wrap;gap:12px;font-size:.8rem;color:var(--sd-muted)">
                @if($active->topicLabel())<span>حوزه: <b style="color:var(--sd-ink)">{{ $active->topicLabel() }}</b></span>@endif
                @if($active->serviceLabel())<span>خدمت: <b style="color:var(--sd-ink)">{{ $active->serviceLabel() }}</b></span>@endif
                @if($active->relatedProduct ?? null)<span>محصول: <b style="color:var(--sd-ink)">{{ $active->relatedProduct->title }}</b></span>@endif
            </div>
            @endif

            <div class="sd-messages" id="chatMessages">
                @foreach($active->messages as $m)
                    @php $isStaff = $m->isFromStaff(); @endphp
                    <div class="sd-msg {{ $isStaff ? 'sd-msg-user' : 'sd-msg-staff' }}">
                        @unless($isStaff)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-user"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble">
                                <pre>{{ $m->body }}</pre>
                                @if(!empty($m->attachments))
                                    <div class="tk-attach-list">
                                        @foreach((array)$m->attachments as $att)
                                            <a href="{{ asset('storage/'.($att['path'] ?? '')) }}" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-paperclip"></i> {{ $att['name'] ?? 'پیوست' }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <time class="sd-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isStaff ? 'پشتیبانی' : 'کاربر' }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            <form class="sd-composer" method="post" action="{{ route('admin.tickets.reply', $active) }}">
                @csrf
                <div class="sd-composer-inner" style="flex-wrap:wrap">
                    <div class="sd-input-wrap" style="flex:1 1 100%">
                        <textarea name="body" rows="2" required maxlength="5000" placeholder="پاسخ حرفه‌ای، شفاف و راه‌حل‌محور بنویسید..." dir="auto"></textarea>
                    </div>
                    <div style="display:flex;width:100%;gap:8px;align-items:center;justify-content:space-between;margin-top:4px">
                        <select name="status" style="border:1px solid var(--sd-line);border-radius:11px;padding:8px 12px;font:inherit;font-size:0.86rem">
                            <option value="answered">پاسخ داده‌شده</option>
                            <option value="pending">در حال بررسی</option>
                            <option value="open">باز نگه دار</option>
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
                    <p>از فهرست یک تیکت را انتخاب کنید و پاسخ دقیق ارسال کنید.</p>
                </div>
            </div>
        @endif
    </section>
</div>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif
@endsection
