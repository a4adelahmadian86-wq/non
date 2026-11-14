@extends('layouts.admin')

@section('admin-content')
@php
    $slaService = app(\App\Services\SupportSlaService::class);
    $active = $active ?? null;
    $stats = $stats ?? ['needs_reply'=>0,'open'=>0,'user_replied'=>0,'answered'=>0,'pending'=>0,'closed'=>0,'high'=>0,'sla_breached'=>0,'sla_at_risk'=>0];
    $canned = $canned ?? [];
    $agents = $agents ?? collect();
    $filterValue = static fn ($value) => $value !== null && $value !== '';
    $filterQs = array_filter([
        'q' => request('q'),
        'status' => request('status'),
        'department' => request('department'),
        'priority' => request('priority'),
        'source' => request('source'),
        'topic_area' => request('topic_area'),
        'sla' => request('sla'),
        'assigned' => request('assigned'),
        'created_from' => request('created_from'),
        'created_to' => request('created_to'),
    ], $filterValue);
    $base = array_filter([
        'q' => request('q'),
        'department' => request('department'),
        'priority' => request('priority'),
        'source' => request('source'),
        'topic_area' => request('topic_area'),
        'sla' => request('sla'),
        'assigned' => request('assigned'),
        'created_from' => request('created_from'),
        'created_to' => request('created_to'),
    ], $filterValue);
    $statusBase = array_filter(['status' => request('status')], $filterValue);
    $chipFilters = array_merge($base, $statusBase);
    $statusLabels = ['open'=>'باز','user_replied'=>'پاسخ کاربر','pending'=>'منتظر کاربر','answered'=>'پاسخ‌شده','closed'=>'بسته'];
    $departmentLabels = [
        \App\Services\SupportTicketService::DEPARTMENT_SUPPORT => 'پشتیبانی',
        \App\Services\SupportTicketService::DEPARTMENT_SALES => 'فروش',
        \App\Services\SupportTicketService::DEPARTMENT_BILLING => 'مالی',
        \App\Services\SupportTicketService::DEPARTMENT_COMPLAINT => 'شکایات',
    ];
    $priorityLabels = [
        \App\Services\SupportTicketService::PRIORITY_LOW => 'کم',
        \App\Services\SupportTicketService::PRIORITY_NORMAL => 'متوسط',
        \App\Services\SupportTicketService::PRIORITY_HIGH => 'زیاد',
    ];
    $sourceLabels = [
        \App\Services\SupportTicketService::SOURCE_DASHBOARD => 'پنل',
        \App\Services\SupportTicketService::SOURCE_WIDGET => 'ویجت',
    ];
    $topicAreaLabels = [
        \App\Services\SupportTicketService::TOPIC_AREA_SERVICES => 'خدمات',
        \App\Services\SupportTicketService::TOPIC_AREA_STORE => 'فروشگاه',
    ];
    $slaFilterLabels = [
        \App\Services\SupportTicketService::SLA_FILTER_AT_RISK => 'نزدیک نقض',
        \App\Services\SupportTicketService::SLA_FILTER_BREACHED => 'نقض‌شده',
    ];
    $assignmentFilterLabels = [
        \App\Services\SupportTicketService::ASSIGNMENT_FILTER_ME => 'ارجاع به من',
        \App\Services\SupportTicketService::ASSIGNMENT_FILTER_UNASSIGNED => 'بدون ارجاع',
    ];
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261114inboxfilters">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261114managedattachments">
<link rel="stylesheet" href="{{ asset('css/support-sla.css') }}?v=20261019sla">

<div class="sd-shell" dir="rtl">
    <aside class="sd-aside">
        <div class="sd-aside-head">
            <div>
                <h1>میز پشتیبانی</h1>
                <p>مدیریت و پاسخ حرفه‌ای به تیکت‌ها</p>
            </div>
            @if(app(\App\Services\AdminConsoleService::class)->sectionAllowed(auth()->user(), 'support_admin'))
            <a href="{{ route('admin.support.report') }}" class="sd-btn" style="background:#eef4ff;color:#1769ff;font-size:.78rem;text-decoration:none">گزارش SLA</a>
            @endif
        </div>

        <div class="sd-admin-stats" style="grid-template-columns:repeat(3,1fr)">
            <div class="sd-admin-stat"><b>{{ $stats['needs_reply'] ?? 0 }}</b><span>نیازمند پاسخ</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['high'] ?? 0 }}</b><span>اولویت بالا</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['pending'] ?? 0 }}</b><span>منتظر کاربر</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['user_replied'] ?? 0 }}</b><span>پاسخ کاربر</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['answered'] ?? 0 }}</b><span>پاسخ‌شده</span></div>
            <div class="sd-admin-stat"><b>{{ $stats['closed'] ?? 0 }}</b><span>بسته</span></div>
        </div>

        <div class="sd-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <form method="get" action="{{ route('admin.support.management') }}" style="width:100%">
                @foreach(request()->only(['status','department','priority','source','topic_area','sla','assigned','created_from','created_to']) as $k=>$v)
                    @if($v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
                <input type="search" name="q" value="{{ request('q') }}" placeholder="کد تیکت، موضوع، ایمیل..." autocomplete="off">
            </form>
        </div>

        <div class="sd-filters">
            <a class="sd-chip {{ !request('status')||request('status')==='all'?'is-on':'' }}" href="{{ route('admin.support.management', $base) }}">همه</a>
            <a class="sd-chip {{ request('status')===\App\Services\SupportTicketService::STATUS_FILTER_NEEDS_REPLY?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>\App\Services\SupportTicketService::STATUS_FILTER_NEEDS_REPLY]) }}">نیازمند پاسخ</a>
            @foreach(\App\Services\SupportTicketService::STATUSES as $status)
                <a class="sd-chip {{ request('status')===$status?'is-on':'' }}" href="{{ route('admin.support.management', $base+['status'=>$status]) }}">{{ $statusLabels[$status] ?? $status }}</a>
            @endforeach
            <a class="sd-chip {{ request('sla')===\App\Services\SupportTicketService::SLA_FILTER_AT_RISK?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['sla'=>\App\Services\SupportTicketService::SLA_FILTER_AT_RISK])) }}" style="border-color:#fde68a;color:#b45309">نزدیک نقض ({{ $stats['sla_at_risk'] ?? 0 }})</a>
            <a class="sd-chip {{ request('sla')===\App\Services\SupportTicketService::SLA_FILTER_BREACHED?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['sla'=>\App\Services\SupportTicketService::SLA_FILTER_BREACHED])) }}" style="border-color:#fecaca;color:#b91c1c">نقض SLA ({{ $stats['sla_breached'] ?? 0 }})</a>
            <a class="sd-chip {{ request('priority')===\App\Services\SupportTicketService::PRIORITY_HIGH?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['priority'=>\App\Services\SupportTicketService::PRIORITY_HIGH])) }}" style="border-color:#fecaca;color:#b91c1c">اولویت بالا ({{ $stats['high'] ?? 0 }})</a>
            <a class="sd-chip {{ request('department')===\App\Services\SupportTicketService::DEPARTMENT_BILLING?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['department'=>\App\Services\SupportTicketService::DEPARTMENT_BILLING])) }}">مالی</a>
            <a class="sd-chip {{ request('assigned')===\App\Services\SupportTicketService::ASSIGNMENT_FILTER_ME?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['assigned'=>\App\Services\SupportTicketService::ASSIGNMENT_FILTER_ME])) }}">ارجاع به من</a>
            <a class="sd-chip {{ request('assigned')===\App\Services\SupportTicketService::ASSIGNMENT_FILTER_UNASSIGNED?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['assigned'=>\App\Services\SupportTicketService::ASSIGNMENT_FILTER_UNASSIGNED])) }}">بدون ارجاع</a>
            @foreach(\App\Services\SupportTicketService::SOURCES as $source)
                <a class="sd-chip {{ request('source')===$source?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['source'=>$source])) }}">ثبت از {{ $sourceLabels[$source] ?? $source }}</a>
            @endforeach
            @foreach(\App\Services\SupportTicketService::TOPIC_AREAS as $topicArea)
                <a class="sd-chip {{ request('topic_area')===$topicArea?'is-on':'' }}" href="{{ route('admin.support.management', array_merge($chipFilters, ['topic_area'=>$topicArea])) }}">موضوع {{ $topicAreaLabels[$topicArea] ?? $topicArea }}</a>
            @endforeach
        </div>

        <form class="sd-filter-form" method="get" action="{{ route('admin.support.management') }}">
            <input type="hidden" name="q" value="{{ request('q') }}">
            <label class="sd-filter-field">
                <span>وضعیت</span>
                <select name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="{{ \App\Services\SupportTicketService::STATUS_FILTER_NEEDS_REPLY }}" @selected(request('status') === \App\Services\SupportTicketService::STATUS_FILTER_NEEDS_REPLY)>نیازمند پاسخ</option>
                    @foreach(\App\Services\SupportTicketService::STATUSES as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>دپارتمان</span>
                <select name="department">
                    <option value="">همه دپارتمان‌ها</option>
                    @foreach(\App\Services\SupportTicketService::DEPARTMENTS as $department)
                        <option value="{{ $department }}" @selected(request('department') === $department)>{{ $departmentLabels[$department] ?? $department }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>اولویت</span>
                <select name="priority">
                    <option value="">همه اولویت‌ها</option>
                    @foreach(\App\Services\SupportTicketService::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(request('priority') === $priority)>{{ $priorityLabels[$priority] ?? $priority }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>کانال ثبت</span>
                <select name="source">
                    <option value="">همه کانال‌ها</option>
                    @foreach(\App\Services\SupportTicketService::SOURCES as $source)
                        <option value="{{ $source }}" @selected(request('source') === $source)>{{ $sourceLabels[$source] ?? $source }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>حوزه موضوع</span>
                <select name="topic_area">
                    <option value="">همه حوزه‌ها</option>
                    @foreach(\App\Services\SupportTicketService::TOPIC_AREAS as $topicArea)
                        <option value="{{ $topicArea }}" @selected(request('topic_area') === $topicArea)>{{ $topicAreaLabels[$topicArea] ?? $topicArea }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>وضعیت SLA</span>
                <select name="sla">
                    <option value="">همه وضعیت‌های SLA</option>
                    @foreach(\App\Services\SupportTicketService::SLA_FILTERS as $slaFilter)
                        <option value="{{ $slaFilter }}" @selected(request('sla') === $slaFilter)>{{ $slaFilterLabels[$slaFilter] ?? $slaFilter }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>ارجاع</span>
                <select name="assigned">
                    <option value="">همه تیکت‌ها</option>
                    @foreach(\App\Services\SupportTicketService::ASSIGNMENT_FILTERS as $assignmentFilter)
                        <option value="{{ $assignmentFilter }}" @selected(request('assigned') === $assignmentFilter)>{{ $assignmentFilterLabels[$assignmentFilter] ?? $assignmentFilter }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sd-filter-field">
                <span>از تاریخ ثبت</span>
                <input type="date" name="created_from" value="{{ request('created_from') }}">
            </label>
            <label class="sd-filter-field">
                <span>تا تاریخ ثبت</span>
                <input type="date" name="created_to" value="{{ request('created_to') }}">
            </label>
            <div class="sd-filter-actions">
                <button type="submit" class="sd-btn sd-btn-primary">اعمال فیلترها</button>
                <a href="{{ route('admin.support.management') }}">پاک‌کردن فیلترها</a>
            </div>
        </form>

        <div class="sd-list" id="ticketList">
            @forelse($tickets as $t)
                @php $lastPublicPreview = $t->last_public_message_body; @endphp
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
                    @if(filled($lastPublicPreview))
                        <div class="sd-item-preview">{{ \Illuminate\Support\Str::limit($lastPublicPreview, 55) }}</div>
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
                            @php
                                $slaBars = [];
                                if ($active->sla_first_due_at && ! $active->first_response_at) {
                                    $total = max(1, (int) config('support.sla_first_response_minutes.'.$active->priority, 240));
                                    $remaining = $slaService->minutesUntilDeadline($active->sla_first_due_at);
                                    $used = max(0, $total - $remaining);
                                    $pct = min(100, (int) round(100 * $used / $total));
                                    $slaBars[] = ['label' => 'پاسخ اول', 'pct' => $pct, 'color' => $slaA['color'] ?? 'ok'];
                                }
                                if ($active->sla_resolve_due_at && $active->status !== 'closed' && $active->status !== 'pending') {
                                    $total = max(1, (int) config('support.sla_resolution_minutes.'.$active->priority, 1440));
                                    $remaining = $slaService->minutesUntilDeadline($active->sla_resolve_due_at);
                                    $used = max(0, $total - $remaining);
                                    $pct = min(100, (int) round(100 * $used / $total));
                                    $slaBars[] = ['label' => 'حل', 'pct' => $pct, 'color' => $slaR['color'] ?? 'ok'];
                                }
                            @endphp
                            @if($active->csat_score)
                                <span class="sla-pill sla-ok" title="{{ $active->csat_comment }}">CSAT: {{ $active->csat_score }}/5</span>
                            @elseif($active->status === 'closed')
                                <span class="sla-pill sla-muted">بدون امتیاز CSAT</span>
                            @endif
                        </div>
                        @if(!empty($slaBars))
                            <div style="display:grid;gap:6px;margin-top:10px;max-width:360px">
                                @foreach($slaBars as $bar)
                                    <div>
                                        <div style="display:flex;justify-content:space-between;font-size:.72rem;color:#64748b;margin-bottom:3px">
                                            <span>{{ $bar['label'] }}</span>
                                            <span>{{ $bar['pct'] }}٪</span>
                                        </div>
                                        <div style="height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden">
                                            <div style="height:100%;width:{{ $bar['pct'] }}%;background:{{ $bar['color']==='danger'||$bar['color']==='breached' ? '#ef4444' : ($bar['color']==='warn'||$bar['color']==='warning' ? '#f59e0b' : '#1769ff') }};border-radius:999px"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <form method="post" action="{{ route('admin.tickets.status', $active) }}">
                        @csrf
                        <input type="hidden" name="status" value="pending">
                        <button type="submit" class="sd-btn" style="background:#fef3c7;color:#92400e;font-size:.78rem">منتظر پاسخ کاربر</button>
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
                    <form method="post" action="{{ route('admin.tickets.meta', $active) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        @csrf
                        <select name="priority" style="border:1px solid var(--sd-line);border-radius:10px;padding:6px 10px;font:inherit;font-size:.78rem">
                            <option value="low" @selected(($active->priority ?? '')==='low')>اولویت کم</option>
                            <option value="normal" @selected(($active->priority ?? 'normal')==='normal')>اولویت متوسط</option>
                            <option value="high" @selected(($active->priority ?? '')==='high')>اولویت زیاد</option>
                        </select>
                        <select name="department" style="border:1px solid var(--sd-line);border-radius:10px;padding:6px 10px;font:inherit;font-size:.78rem">
                            <option value="support" @selected(($active->department ?? 'support')==='support')>پشتیبانی</option>
                            <option value="sales" @selected(($active->department ?? '')==='sales')>فروش</option>
                            <option value="billing" @selected(($active->department ?? '')==='billing')>مالی</option>
                            <option value="complaint" @selected(($active->department ?? '')==='complaint')>شکایات</option>
                        </select>
                        <button type="submit" class="sd-btn" style="background:#f1f5f9;color:#334155;font-size:.78rem">ذخیره متا</button>
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
                @if($activeHistory['has_older'] ?? false)
                    <button type="button" id="loadOlderAdminMessages" class="sd-btn" style="display:block;margin:8px auto;background:#eef4ff;color:#1769ff">نمایش پیام‌های قدیمی‌تر</button>
                @endif
                <div id="adminThread" data-poll-url="{{ route('support.messages.poll', $active) }}" data-last-id="{{ $activeHistory['last_scanned_id'] ?? $active->messages->last()?->id ?? 0 }}" data-oldest-id="{{ $activeHistory['oldest_scanned_id'] ?? $active->messages->first()?->id ?? 0 }}">
                @foreach($active->messages as $m)
                    @php $isStaff = $m->isFromStaff(); $isInternal = (bool) $m->is_internal; @endphp
                    <div class="sd-msg {{ $isStaff ? 'sd-msg-user' : 'sd-msg-staff' }}" data-msg-id="{{ $m->id }}">
                        @unless($isStaff)
                            <div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-user"></i></div>
                        @endunless
                        <div class="sd-msg-col">
                            <div class="sd-bubble" style="{{ $isInternal ? 'border:1px dashed #f59e0b;background:#fffbeb;color:#78350f' : '' }}">
                                @if($isInternal)<div style="font-size:.72rem;font-weight:800;margin-bottom:4px">یادداشت داخلی</div>@endif
                                <pre>{{ $m->body }}</pre>
                                @if(!empty($m->attachments))
                                    <div class="tk-attach-list">
                                        @foreach((array)$m->attachments as $index => $att)
                                            @if(!empty($att['quarantined']))
                                                <span class="tk-attach-unavailable" title="فایل در بررسی امنیتی پذیرفته نشده است">
                                                    <i class="fa-solid fa-shield-halved"></i> {{ $att['name'] ?? 'پیوست' }} · قرنطینه امنیتی
                                                </span>
                                            @elseif(empty($att['managed_file_id']))
                                                <span class="tk-attach-pending">
                                                    <i class="fa-solid fa-hourglass-half"></i> {{ $att['name'] ?? 'پیوست' }} · در حال انتقال به فضای امن
                                                </span>
                                            @else
                                                <a href="{{ route('support.attachment', ['ticket' => $active->id, 'message' => $m->id, 'index' => $index]) }}" target="_blank" rel="noopener">
                                                    <i class="fa-solid fa-paperclip"></i> {{ $att['name'] ?? 'پیوست' }}
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <time class="sd-msg-time">{{ $m->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }} · {{ $isInternal ? 'داخلی' : ($isStaff ? 'پشتیبانی' : 'کاربر') }}</time>
                        </div>
                    </div>
                @endforeach
                </div>
            </div>

            @php
                $auditStatusLabels = ['open' => 'باز', 'pending' => 'در انتظار مشتری', 'answered' => 'پاسخ داده شده', 'user_replied' => 'پاسخ مشتری', 'closed' => 'بسته'];
                $auditPriorityLabels = ['low' => 'کم', 'normal' => 'عادی', 'high' => 'زیاد'];
                $auditDepartmentLabels = ['support' => 'پشتیبانی', 'sales' => 'فروش', 'billing' => 'مالی', 'complaint' => 'شکایات'];
            @endphp
            <details style="padding:10px 16px;border-top:1px solid var(--sd-line);background:var(--sd-card)">
                <summary style="cursor:pointer;font-size:.82rem;font-weight:800;color:var(--sd-ink)">
                    تاریخچه تغییرات ممیزی‌شده ({{ $auditEvents->count() }})
                    <span style="font-size:.72rem;font-weight:500;color:var(--sd-muted)"> · آخرین ۵۰ رویداد</span>
                </summary>
                @if($auditEvents->isEmpty())
                    <p style="margin:10px 0 2px;font-size:.78rem;color:var(--sd-muted)">برای این تیکت هنوز رویداد ممیزی‌شده‌ای ثبت نشده است.</p>
                @else
                    <ol style="margin:12px 0 0;padding:0 18px 0 0;display:grid;gap:10px">
                        @foreach($auditEvents as $auditEvent)
                            @php
                                $auditMeta = (array) ($auditEvent->payload_meta ?? []);
                                $auditActor = $auditUserLabels[$auditEvent->user_id] ?? null;
                                $auditActorLabel = $auditActor ?? ($auditEvent->user_id ? 'کاربر حذف‌شده' : 'سامانه');
                                if ($auditEvent->event_type === 'support.ticket.created' && ($auditMeta['initiator_type'] ?? null) === 'guest') {
                                    $auditActorLabel = 'مهمان';
                                }
                            @endphp
                            <li style="padding:8px 10px;border-right:2px solid var(--sd-line);background:var(--sd-bg);border-radius:8px;list-style-position:outside">
                                <div style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;justify-content:space-between">
                                    <strong style="font-size:.78rem;color:var(--sd-ink)">
                                        @if($auditEvent->event_type === 'support.ticket.created')
                                            @php
                                                $createdSource = match ($auditMeta['source'] ?? null) {
                                                    'dashboard' => 'داشبورد کاربری',
                                                    'widget' => 'ابزارک پشتیبانی',
                                                    default => 'مسیر ثبت تیکت',
                                                };
                                            @endphp
                                            ایجاد تیکت از {{ $createdSource }}
                                        @elseif($auditEvent->event_type === 'support.ticket.status_transitioned')
                                            @php
                                                $fromStatus = $auditStatusLabels[$auditMeta['from_status'] ?? ''] ?? ($auditMeta['from_status'] ?? '—');
                                                $toStatus = $auditStatusLabels[$auditMeta['to_status'] ?? ''] ?? ($auditMeta['to_status'] ?? '—');
                                            @endphp
                                            تغییر وضعیت: از {{ $fromStatus }} به {{ $toStatus }}
                                        @elseif($auditEvent->event_type === 'support.ticket.metadata_changed')
                                            @php
                                                $before = (array) ($auditMeta['before'] ?? []);
                                                $after = (array) ($auditMeta['after'] ?? []);
                                                $changes = [];
                                                if (($before['priority'] ?? null) !== ($after['priority'] ?? null)) {
                                                    $changes[] = 'اولویت: '.($auditPriorityLabels[$before['priority'] ?? ''] ?? ($before['priority'] ?? '—')).' ← '.($auditPriorityLabels[$after['priority'] ?? ''] ?? ($after['priority'] ?? '—'));
                                                }
                                                if (($before['department'] ?? null) !== ($after['department'] ?? null)) {
                                                    $changes[] = 'دپارتمان: '.($auditDepartmentLabels[$before['department'] ?? ''] ?? ($before['department'] ?? '—')).' ← '.($auditDepartmentLabels[$after['department'] ?? ''] ?? ($after['department'] ?? '—'));
                                                }
                                            @endphp
                                            تغییر مشخصات: {{ implode('، ', $changes) ?: 'به‌روزرسانی مشخصات تیکت' }}
                                        @elseif($auditEvent->event_type === 'support.ticket.assignment_changed')
                                            @php
                                                $fromAssigneeId = $auditMeta['from_assigned_to'] ?? null;
                                                $toAssigneeId = $auditMeta['to_assigned_to'] ?? null;
                                                $fromAssignee = $fromAssigneeId ? ($auditUserLabels[$fromAssigneeId] ?? 'کارشناس دیگر') : 'بدون ارجاع';
                                                $toAssignee = $toAssigneeId ? ($auditUserLabels[$toAssigneeId] ?? 'کارشناس دیگر') : 'بدون ارجاع';
                                            @endphp
                                            تغییر ارجاع: از {{ $fromAssignee }} به {{ $toAssignee }}
                                        @elseif(in_array($auditEvent->event_type, ['support.ticket.sla_alert_queued', 'support.ticket.sla_alert_dispatched'], true))
                                            @php
                                                $slaKindLabel = match ($auditMeta['kind'] ?? null) {
                                                    'first_response' => 'اولین پاسخ',
                                                    'resolution' => 'حل تیکت',
                                                    default => 'مهلت پشتیبانی',
                                                };
                                                $slaEventLabel = ($auditMeta['event'] ?? null) === 'breached' ? 'نقض مهلت' : 'نزدیک به پایان';
                                            @endphp
                                            {{ $auditEvent->event_type === 'support.ticket.sla_alert_queued' ? 'ثبت هشدار SLA در صف ارسال' : 'تحویل هشدار SLA به کانال‌های اطلاع‌رسانی' }}: {{ $slaKindLabel }} — {{ $slaEventLabel }}
                                        @elseif($auditEvent->event_type === 'support.ticket.message_added')
                                            @php
                                                $messageTypeLabel = match ($auditMeta['message_type'] ?? null) {
                                                    'internal_note' => 'یادداشت داخلی',
                                                    'system_note' => 'یادداشت سیستمی',
                                                    'staff_reply' => 'پاسخ کارشناس',
                                                    'customer_reply' => 'پاسخ مشتری',
                                                    'guest_reply' => 'پاسخ مهمان',
                                                    default => 'پیام',
                                                };
                                            @endphp
                                            ثبت {{ $messageTypeLabel }} در گفتگو
                                        @elseif($auditEvent->event_type === 'support.ticket.csat_submitted')
                                            ثبت امتیاز رضایت مشتری
                                        @else
                                            {{ $auditEvent->event_type }}
                                        @endif
                                    </strong>
                                    <time style="font-size:.7rem;color:var(--sd-muted)" datetime="{{ $auditEvent->created_at?->toIso8601String() }}">
                                        {{ $auditEvent->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}
                                    </time>
                                </div>
                                <div style="margin-top:4px;font-size:.72rem;color:var(--sd-muted)">عامل تغییر: {{ $auditActorLabel }}</div>
                                @if($auditEvent->event_type === 'support.ticket.status_transitioned')
                                    @php
                                        $slaBefore = (array) ($auditMeta['sla_before'] ?? []);
                                        $slaAfter = (array) ($auditMeta['sla_after'] ?? []);
                                    @endphp
                                    @if(empty($slaBefore['resolution_paused_at']) && !empty($slaAfter['resolution_paused_at']))
                                        <div style="margin-top:4px;font-size:.72rem;color:#b45309">ساعت SLA حل متوقف شد</div>
                                    @elseif(!empty($slaBefore['resolution_paused_at']) && empty($slaAfter['resolution_paused_at']))
                                        <div style="margin-top:4px;font-size:.72rem;color:#047857">ساعت SLA حل از سر گرفته شد</div>
                                    @endif
                                @elseif(in_array($auditEvent->event_type, ['support.ticket.sla_alert_queued', 'support.ticket.sla_alert_dispatched'], true))
                                    <div style="margin-top:4px;font-size:.72rem;color:var(--sd-muted)">
                                        @if(($auditMeta['event'] ?? null) === 'warning' && isset($auditMeta['minutes_left']))
                                            زمان باقی‌مانده هنگام ثبت: {{ (int) $auditMeta['minutes_left'] }} دقیقه
                                        @endif
                                        @if($auditEvent->event_type === 'support.ticket.sla_alert_dispatched' && isset($auditMeta['attempts']))
                                            · تلاش ارسال: {{ (int) $auditMeta['attempts'] }}
                                        @endif
                                    </div>
                                @elseif($auditEvent->event_type === 'support.ticket.message_added')
                                    <div style="margin-top:4px;font-size:.72rem;color:var(--sd-muted)">
                                        @if(!empty($auditMeta['is_internal']))
                                            داخلی
                                        @else
                                            عمومی
                                        @endif
                                        @if(!empty($auditMeta['attachment_count']))
                                            · پیوست: {{ (int) $auditMeta['attachment_count'] }}
                                        @endif
                                    </div>
                                @elseif($auditEvent->event_type === 'support.ticket.csat_submitted')
                                    <div style="margin-top:4px;font-size:.72rem;color:var(--sd-muted)">
                                        امتیاز ثبت‌شده: {{ (int) ($auditMeta['score'] ?? 0) }} از ۵
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </details>

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
                            <select name="status" id="adminReplyStatus" style="border:1px solid var(--sd-line);border-radius:11px;padding:8px 12px;font:inherit;font-size:0.86rem">
                                <option value="answered">پاسخ داده‌شده</option>
                                <option value="pending">منتظر پاسخ کاربر</option>
                                <option value="open">باز نگه دار</option>
                                <option value="closed">بستن تیکت</option>
                            </select>
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:var(--sd-muted);cursor:pointer">
                                <input type="checkbox" name="is_internal" id="adminInternalNote" value="1"> یادداشت داخلی
                            </label>
                            <label style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-size:.82rem;color:var(--sd-blue)">
                                <i class="fa-solid fa-paperclip"></i> پیوست
                                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx" hidden>
                            </label>
                        </div>
                        <button type="submit" class="sd-btn sd-btn-primary">ارسال پاسخ</button>
                    </div>
                    <div id="internalNoteHint" hidden style="width:100%;font-size:.76rem;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:7px 10px">
                        یادداشت داخلی فقط برای کارشناسان قابل مشاهده است و وضعیت تیکت یا ساعت SLA را تغییر نمی‌دهد.
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

  const internalNote = document.getElementById('adminInternalNote');
  const replyStatus = document.getElementById('adminReplyStatus');
  const internalHint = document.getElementById('internalNoteHint');
  const syncInternalNote = () => {
    const isInternal = !!internalNote?.checked;
    if (replyStatus) {
      replyStatus.disabled = isInternal;
      replyStatus.setAttribute('aria-disabled', isInternal ? 'true' : 'false');
    }
    if (internalHint) internalHint.hidden = !isInternal;
  };
  internalNote?.addEventListener('change', syncInternalNote);
  syncInternalNote();

  // Build one message node for both polling and older-history loading.
  const thread = document.getElementById('adminThread');
  const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const buildAdminMessageNode = (m) => {
    const isStaff = !!m.is_staff;
    const isInternal = !!m.is_internal;
    const attachmentsHtml = Array.isArray(m.attachments) && m.attachments.length
      ? '<div class="tk-attach-list">' + m.attachments.map((a) => {
          const name = esc(a.name || 'پیوست');
          if (a.quarantined) return '<span class="tk-attach-unavailable"><i class="fa-solid fa-shield-halved"></i> ' + name + ' · قرنطینه امنیتی</span>';
          if (!a.available) return '<span class="tk-attach-pending"><i class="fa-solid fa-hourglass-half"></i> ' + name + ' · در حال انتقال به فضای امن</span>';
          return '<a href="' + esc(a.url || '#') + '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i> ' + name + '</a>';
        }).join('') + '</div>'
      : '';
    const wrap = document.createElement('div');
    wrap.className = 'sd-msg ' + (isStaff ? 'sd-msg-user' : 'sd-msg-staff');
    wrap.dataset.msgId = String(m.id);
    wrap.innerHTML = (isStaff ? '' : '<div class="sd-avatar sd-avatar-sm"><i class="fa-solid fa-user"></i></div>') +
      '<div class="sd-msg-col"><div class="sd-bubble"' + (isInternal ? ' style="border:1px dashed #f59e0b;background:#fffbeb;color:#78350f"' : '') + '>' +
      (isInternal ? '<div style="font-size:.72rem;font-weight:800;margin-bottom:4px">یادداشت داخلی</div>' : '') +
      '<pre>' + esc(m.body) + '</pre>' + attachmentsHtml + '</div>' +
      '<time class="sd-msg-time">' + esc(m.time) + ' · ' + (isInternal ? 'داخلی' : (isStaff ? 'پشتیبانی' : 'کاربر')) + '</time></div>';
    return wrap;
  };

  const olderButton = document.getElementById('loadOlderAdminMessages');
  if (thread && thread.dataset.pollUrl) {
    let lastId = parseInt(thread.dataset.lastId || '0', 10) || 0;
    let pollInFlight = false;
    let olderInFlight = false;

    olderButton?.addEventListener('click', async () => {
      if (olderInFlight || pollInFlight) return;
      const beforeId = Number(thread.dataset.oldestId || 0);
      if (!beforeId || !box) return;
      olderInFlight = true;
      olderButton.disabled = true;
      olderButton.textContent = 'در حال بارگذاری...';
      let loadFailed = false;
      const previousHeight = box.scrollHeight;
      const previousTop = box.scrollTop;
      try {
        const url = thread.dataset.pollUrl + (thread.dataset.pollUrl.includes('?') ? '&' : '?') + 'before_id=' + beforeId;
        const response = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error('history request failed');
        const data = await response.json();
        if (!data.ok) throw new Error('history response invalid');

        const nodes = (Array.isArray(data.messages) ? data.messages : []).map(buildAdminMessageNode);
        nodes.reverse().forEach((node) => thread.insertBefore(node, thread.firstChild));

        const nextBeforeId = Number(data.next_before_id);
        if (Number.isFinite(nextBeforeId) && nextBeforeId > 0) {
          thread.dataset.oldestId = String(nextBeforeId);
        }
        if (!data.has_more) olderButton.hidden = true;
        box.scrollTop = previousTop + (box.scrollHeight - previousHeight);
      } catch (e) {
        loadFailed = true;
      } finally {
        olderInFlight = false;
        olderButton.disabled = false;
        if (!olderButton.hidden) olderButton.textContent = loadFailed ? 'خطا؛ دوباره تلاش کنید' : 'نمایش پیام‌های قدیمی‌تر';
      }
    });

    setInterval(async () => {
      if (pollInFlight || olderInFlight || document.hidden) return;
      pollInFlight = true;
      try {
        const url = thread.dataset.pollUrl + (thread.dataset.pollUrl.includes('?') ? '&' : '?') + 'after_id=' + lastId;
        const res = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok) return;

        const shouldStickToBottom = box
          && (box.scrollHeight - box.scrollTop - box.clientHeight <= 96);

        (Array.isArray(data.messages) ? data.messages : []).forEach((m) => {
          if (m.id <= lastId) return;
          lastId = m.id;
          thread.appendChild(buildAdminMessageNode(m));
        });

        const serverCursor = Number(data.last_id);
        if (Number.isFinite(serverCursor)) lastId = Math.max(lastId, serverCursor);
        thread.dataset.lastId = String(lastId);

        if (shouldStickToBottom && box) box.scrollTop = box.scrollHeight;
      } catch (e) {
      } finally {
        pollInFlight = false;
      }
    }, 8000);
  }
})();
</script>
@endpush
@endsection
