@extends('layouts.app')

@section('content')
@php
    $slaService = app(\App\Services\SupportSlaService::class);
    $active = $active ?? null;
    $stats = $stats ?? ['needs_reply'=>0,'open'=>0,'user_replied'=>0,'answered'=>0,'pending'=>0,'closed'=>0,'high'=>0];
    $canned = $canned ?? [];
    $agents = $agents ?? collect();
    $filterQs = array_filter([
        'q' => request('q'),
        'status' => request('status'),
        'department' => request('department'),
        'priority' => request('priority'),
    ], fn($v) => $v !== null && $v !== '');
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261019pro">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019pro">
<link rel="stylesheet" href="{{ asset('css/support-sla.css') }}?v=20261019sla">

<div class="sd-shell" dir="rtl">
    <aside class="sd-aside">
        <div class="sd-aside-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>مدیریت و پاسخ حرفه‌ای به تیکت‌ها</p>
            </div>
            <a href="{{ route('admin.support.report') }}" class="sd-btn" style="background:#eef4ff;color:#1769ff;font-size:.78rem;text-decoration:none">گزارش SLA</a>
        </div>

        <div class="sd-admin-stats" style="grid-template-columns:repeat(3,1fr)">
            <div class="sd-admin-stat"><b>{{ $stats['needs_reply'] ?? 0 }}</b><span>نیازمند پاسخ</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['high'] ?? 0 }}</b><span>اولویت بالا</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['pending'] ?? 0 }}</b><span>در بررسی</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['user_replied'] ?? 0 }}</b><span>پاسخ کاربر</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['answered'] ?? 0 }}</b><span>پاسخ‌شده</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['closed'] ?? 0 }}</b><span>بسته</span></div>
        </div>

        <div class="sd-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <form method="get" action="{{ route('admin.support.management') }}" style="width:100%">
                @foreach(request()->only(['status','department','priority']) as $k=>$v)
                    @if($v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
                <input type="search" name="q" value="{{ request('q') }}" placeholder="کد تیکت، موضوع، ایمیل..." autocomplete="off">
            </form>
        </div>

        <div class="sd-filters">
            @php $base = array_filter(['q'=>request('q'),'department'=>request('department'),'priority'=>request('priority')]); @endphp
            <a class="sd-chip {{ !request('status')||request('status')==='all'?'is-on':'' }}" href="{{ route('admin.support.management', $base) }}">همه</a>
            <a class="sd-chip {{ request('status')==='needs_reply'?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>'needs_reply']) }}">نیازمند پاسخ</a>
            <a class="sd-chip {{ request('status')==='user_replied'?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>'user_replied']) }}">پاسخ کاربر</a>
            <a class="sd-chip {{ request('status')==='pending'?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>'pending']) }}">بررسی</a>
            <a class="sd-chip {{ request('status')==='answered'?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>'answered']) }}">پاسخ‌شده</a>
            <a class="sd-chip {{ request('status')==='closed'?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>'closed']) }}">بسته</a>
        </div>

        <div class="sd-list" id="ticketList">
            @forelse($tickets as $t)
                @php $last = $t->messages->filter(fn($m)=>!$m->is_internal)->last(); @endphp
                <a href="{{ route('admin.support.management', $filterQs + ['ticket' => $t->id]) }}"
                   class="sd-item {{ $active && $active->id === $t->id ? 'is-active' : '' }}">
                    <div class="sd-item-top">
                        <strong>
                            @if($t->priority === 'high')<i class="fa-solid fa-flag" style="color:#dc2626;font-size:.7rem"></i> @endif
                            {{ \Illuminate\Support\Str::limit($t->subject, 40) }}
                        </strong>
                        <span class="sd-badge sd-badge-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="sd-item-meta">
                        <span>{{ $t->displayCode() }}</span>
                        <span>{{ $t->displayName() }}</span>
                        <span>{{ $t->departmentLabel() }}</span>
                        <span>{{ optional($t->last_reply_at ?? $t->updated_at)->diffForHumans() }}</span>
                        @php $sla = $slaService->firstResponseStatus($t); @endphp
                        <span class="sla-pill sla-{{ $sla['color'] }}">{{ $sla['label'] }}</span>
                    </div>
                    @if($last)
                        <div class="sd-item-preview">{{ \Illuminate\Support\Str::limit($last->body, 55) }}</div>
                    @endif
                </a>
            @empty
                <div class="sd-empty"><i class="fa-regular fa-inbox"></i><p>تیکتی با این فیلتر یافت نشد</p></div>
            @endforelse
        </div>

        @if(method_exists($tickets, 'hasPages') && $tickets->hasPages())
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
                            <span>{{ $active->displayCode() }}</span>
                            <span>{{ $active->displayName() }}</span>
                            @if($active->guest_email)<span>{{ $active->guest_email }}</span>@endif
                            @if($active->user?->email)<span>{{ $active->user->email }}</span>@endif
                            <span>{{ $active->departmentLabel() }}</span>
                            <span>اولویت: {{ $active->priorityLabel() }}</span>
                            @if($active->assignee)<span>کارشناس: {{ $active->assignee->name ?? $active->assignee->email }}</span>@endif
                            @php $slaA = $slaService->firstResponseStatus($active); $slaR = $slaService->resolutionStatus($active); @endphp
                            <span class="sla-pill sla-{{ $slaA['color'] }}">{{ $slaA['label'] }}</span>
                            <span class="sla-pill sla-{{ $slaR['color'] }}">{{ $slaR['label'] }}</span>
                            @if($active->csat_score)
                                <span class="sla-pill sla-ok" title="{{ $active->csat_comment }}">CSAT: {{ $active->csat_score }}/5</span>
                            @elseif($active->status === 'closed')
                                <span class="sla-pill sla-muted">بدون امتیاز CSAT</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <form method="post" action="{{ route('admin.tickets.status', $active) }}">
                        @csrf
                        <input type="hidden" name="status" value="pending">
                        <button type="submit" class="sd-btn" style="background:#fef3c7;color:#92400e;font-size:.78rem">در بررسی</button>
                    </form>
                    <form method="post" action="{{ route('admin.tickets.status', $active) }}">
                        @csrf
                        <input type="hidden" name="status" value="closed">
                        <button type="submit" class="sd-btn" style="background:#fef2f2;color:#b91c1c;font-size:.78rem" onclick="return confirm('بستن تیکت؟')">بستن</button>
                    </form>
                    <form method="post" action="{{ route('admin.tickets.assign', $active) }}" style="display:flex;gap:6px;align-items:center">
                        @csrf
                        <select name="assigned_to" style="border:1px solid var(--sd-line);border-radius:10px;padding:6px 10px;font:inherit;font-size:.78rem;max-width:160px">
                            <option value="">بدون ارجاع</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" @selected((int)($active->assigned_to) === (int)$agent->id)>{{ $agent->name ?? $agent->email }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="sd-btn" style="background:#eef4ff;color:#1769ff;font-size:.78rem">ارجاع</button>
                    </form>
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
                    @php $isStaff = $m->isFromStaff(); $isInternal = (bool) $m->is_internal; @endphp
                    <div class="sd-msg {{ $isStaff ? 'sd-msg-user' : 'sd-msg-staff' }}">
                        @unless($isStaff)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-user"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble" style="{{ $isInternal ? 'border:1px dashed #f59e0b;background:#fffbeb;color:#78350f' : '' }}">
                                @if($isInternal)<div style="font-size:.72rem;font-weight:800;margin-bottom:4px">یادداشت داخلی</div>@endif
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
                            <time class="sd-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isInternal ? 'داخلی' : ($isStaff ? 'پشتیبانی' : 'کاربر') }}</time>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(count($canned))
            <div style="padding:8px 14px 0;display:flex;flex-wrap:wrap;gap:6px;background:var(--sd-card);border-top:1px solid var(--sd-line)">
                <span style="font-size:.75rem;color:var(--sd-muted);align-self:center">پاسخ آماده:</span>
                @foreach($canned as $text)
                    <button type="button" class="sd-chip canned-btn" data-text="{{ e($text) }}" style="font-size:.72rem">{{ \Illuminate\Support\Str::limit($text, 28) }}</button>
                @endforeach
            </div>
            @endif

            <form class="sd-composer" method="post" action="{{ route('admin.tickets.reply', $active) }}" enctype="multipart/form-data">
                @csrf
                <div class="sd-composer-inner" style="flex-wrap:wrap">
                    <div class="sd-input-wrap" style="flex:1 1 100%">
                        <textarea name="body" id="adminReplyBody" rows="3" required maxlength="8000" placeholder="پاسخ حرفه‌ای بنویسید..." dir="auto"></textarea>
                    </div>
                    <div style="display:flex;width:100%;gap:8px;align-items:center;justify-content:space-between;margin-top:6px;flex-wrap:wrap">
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <select name="status" style="border:1px solid var(--sd-line);border-radius:11px;padding:8px 12px;font:inherit;font-size:0.86rem">
                                <option value="answered">پاسخ داده‌شده</option>
                                <option value="pending">در حال بررسی</option>
                                <option value="open">باز نگه دار</option>
                                <option value="closed">بستن تیکت</option>
                            </select>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:var(--sd-muted);cursor:pointer">
                                <input type="checkbox" name="is_internal" value="1"> یادداشت داخلی
                            </label>
                            <label style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:.82rem;color:var(--sd-blue)">
                                <i class="fa-solid fa-paperclip"></i> پیوست
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx" hidden>
                            </label>
                        </div>
                        <button type="submit" class="sd-btn sd-btn-primary">ارسال پاسخ</button>
                    </div>
                </div>
            </form>
        @else
            <div class="sd-placeholder">
                <div class="sd-placeholder-card">
                    <div class="sd-avatar sd-avatar-lg"><i class="fa-solid fa-inbox"></i></div>
                    <h2>میز پشتیبانی فراست</h2>
                    <p>از فهرست یک تیکت را انتخاب کنید. صف بر اساس اولویت و «پاسخ کاربر» مرتب شده است.</p>
                </div>
            </div>
        @endif
    </section>
</div>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script>
(() => {
  const box = document.getElementById('chatMessages');
  if (box) box.scrollTop = box.scrollHeight;
  document.querySelectorAll('.canned-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const ta = document.getElementById('adminReplyBody');
      if (!ta) return;
      const t = btn.getAttribute('data-text') || '';
      ta.value = (ta.value ? ta.value.trim() + '\n\n' : '') + t;
      ta.focus();
    });
  });
  const toast = document.querySelector('.sd-toast');
  if (toast) setTimeout(() => toast.remove(), 4000);
})();
</script>
@endpush
@endsection
