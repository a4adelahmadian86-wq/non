@extends('layouts.app')

@section('content')
@php
    $report = $report ?? ['agents' => [], 'totals' => [], 'from' => now(), 'to' => now()];
    $totals = $report['totals'] ?? [];
    $csat = $csat ?? ['count' => 0, 'avg' => null, 'distribution' => [1=>0,2=>0,3=>0,4=>0,5=>0]];
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261019csat">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019csat">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('admin.support.report') }}" class="tk-crumb-strong">گزارش پشتیبانی</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('admin.support.management') }}">میز پشتیبانی</a>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>گزارش عملکرد پشتیبانی</h1>
                    <p>SLA، میانگین زمان پاسخ، رضایت مشتری (CSAT) و عملکرد کارشناسان</p>
                </hgroup>
                <a href="{{ route('admin.support.management') }}" class="tk-btn-ghost">بازگشت به میز</a>
            </div>

            <form method="get" class="tk-toolbar" style="gap:12px;flex-wrap:wrap">
                <label style="font-size:.85rem;color:var(--sd-muted)">از
                    <input type="date" name="from" value="{{ $from }}" style="margin-inline-start:6px;border:1px solid var(--sd-line);border-radius:10px;padding:8px 10px;font:inherit">
                </label>
                <label style="font-size:.85rem;color:var(--sd-muted)">تا
                    <input type="date" name="to" value="{{ $to }}" style="margin-inline-start:6px;border:1px solid var(--sd-line);border-radius:10px;padding:8px 10px;font:inherit">
                </label>
                <button type="submit" class="tk-btn-primary" style="padding:8px 16px">اعمال بازه</button>
            </form>

            <div class="sd-admin-stats" style="margin:16px 0;grid-template-columns:repeat(5,1fr)">
                <div class="sd-admin-stat"><b>{{ $totals['tickets'] ?? 0 }}</b><span>تیکت در بازه</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['closed'] ?? 0 }}</b><span>بسته شده</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['median_first_response_minutes'] !== null ? $totals['median_first_response_minutes'].'د' : ($totals['avg_first_response_minutes'] !== null ? $totals['avg_first_response_minutes'].'د' : '—') }}</b><span>میانه اولین پاسخ (FRT)</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['p90_first_response_minutes'] !== null ? $totals['p90_first_response_minutes'].'د' : '—' }}</b><span>P90 اولین پاسخ</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['sla_rate'] !== null ? $totals['sla_rate'].'%' : '—' }}</b><span>رعایت SLA پاسخ</span></div>
                <div class="sd-admin-stat">
                    <b>{{ $csat['avg'] !== null ? $csat['avg'].' / ۵' : '—' }}</b>
                    <span>میانگین CSAT ({{ $csat['count'] }} رأی)</span>
                </div>
            </div>

            <div class="sd-admin-stats" style="margin:0 0 16px;grid-template-columns:repeat(4,1fr)">
                <div class="sd-admin-stat"><b>{{ $backlog ?? 0 }}</b><span>بک‌لاگ باز (الان)</span></div>
                <div class="sd-admin-stat"><b>{{ $needsReply ?? 0 }}</b><span>نیازمند پاسخ</span></div>
                <div class="sd-admin-stat"><b>{{ $openInRange ?? 0 }} / {{ $closedInRange ?? 0 }}</b><span>ایجاد / بسته‌شده در بازه</span></div>
                <div class="sd-admin-stat"><b>{{ $breachRate !== null ? $breachRate.'%' : '—' }}</b><span>نرخ نقض SLA (بازه)</span></div>
            </div>

            @if($csat['count'] > 0)
                <div class="csat-dist" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px">
                    @for($i = 5; $i >= 1; $i--)
                        @php $n = $csat['distribution'][$i] ?? 0; $pct = $csat['count'] ? round(100 * $n / $csat['count']) : 0; @endphp
                        <div style="flex:1;min-width:90px;background:#f8fafc;border:1px solid var(--sd-line);border-radius:12px;padding:10px 12px;text-align:center">
                            <div style="color:#f59e0b;font-size:.95rem;margin-bottom:4px">
                                @for($s=1;$s<=$i;$s++)<i class="fa-solid fa-star" style="font-size:.7rem"></i>@endfor
                            </div>
                            <b style="display:block;font-size:1.05rem;color:#172b4d">{{ $n }}</b>
                            <span style="font-size:.72rem;color:#6e7f96">{{ $pct }}٪</span>
                        </div>
                    @endfor
                </div>
            @endif

            <div class="tk-table-wrap">
                <table class="tk-table">
                    <thead>
                        <tr>
                            <th>کارشناس</th>
                            <th>ارجاع‌شده</th>
                            <th>بسته شده</th>
                            <th>پاسخ‌ها</th>
                            <th>میانگین FRT</th>
                            <th>میانه FRT</th>
                            <th>رعایت SLA</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($report['agents'] ?? []) as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['assigned'] }}</td>
                                <td>{{ $row['closed'] }}</td>
                                <td>{{ $row['replies'] }}</td>
                                <td>{{ $row['avg_first_response_minutes'] !== null ? $row['avg_first_response_minutes'].' دقیقه' : '—' }}</td>
                                <td>{{ $row['median_first_response_minutes'] !== null ? $row['median_first_response_minutes'].' دقیقه' : '—' }}</td>
                                <td>
                                    @if($row['sla_rate'] !== null)
                                        <span class="tk-status">
                                            <span class="tk-dot {{ $row['sla_rate'] >= 80 ? 'tk-dot-answered' : ($row['sla_rate'] >= 50 ? 'tk-dot-pending' : 'tk-dot-closed') }}"></span>
                                            {{ $row['sla_rate'] }}%
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="tk-empty-row">در این بازه داده‌ای نیست.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:18px;padding:14px;background:#f8fafc;border-radius:12px;font-size:.85rem;color:#64748b;line-height:1.7">
                <b style="color:#334155">اهداف SLA فعلی:</b>
                اولویت بالا {{ config('support.sla_first_response_minutes.high') }}د /
                متوسط {{ config('support.sla_first_response_minutes.normal') }}د /
                کم {{ config('support.sla_first_response_minutes.low') }}د برای اولین پاسخ.
                مهلت حل: بالا {{ config('support.sla_resolution_minutes.high') }}د،
                متوسط {{ config('support.sla_resolution_minutes.normal') }}د،
                کم {{ config('support.sla_resolution_minutes.low') }}د.
                @if(($breached ?? 0) > 0)
                    <br><span style="color:#b91c1c">در حال حاضر {{ $breached }} تیکت خارج از SLA است.</span>
                @endif
            </div>
        </article>
    </main>
</div>
@endsection
