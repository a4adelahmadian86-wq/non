@extends('layouts.app')

@section('content')
@php
    $report = $report ?? ['agents' => [], 'totals' => [], 'from' => now(), 'to' => now()];
    $totals = $report['totals'] ?? [];
@endphp
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261019sla">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019sla">

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
                    <p>SLA، میانگین زمان پاسخ و عملکرد کارشناسان</p>
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

            <div class="sd-admin-stats" style="margin:16px 0;grid-template-columns:repeat(4,1fr)">
                <div class="sd-admin-stat"><b>{{ $totals['tickets'] ?? 0 }}</b><span>تیکت در بازه</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['closed'] ?? 0 }}</b><span>بسته شده</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['avg_first_response_minutes'] !== null ? $totals['avg_first_response_minutes'].'د' : '—' }}</b><span>میانگین اولین پاسخ</span></div>
                <div class="sd-admin-stat"><b>{{ $totals['sla_rate'] !== null ? $totals['sla_rate'].'%' : '—' }}</b><span>رعایت SLA پاسخ</span></div>
            </div>

            <div class="tk-table-wrap">
                <table class="tk-table">
                    <thead>
                        <tr>
                            <th>کارشناس</th>
                            <th>ارجاع‌شده</th>
                            <th>بسته شده</th>
                            <th>پاسخ‌ها</th>
                            <th>میانگین اولین پاسخ</th>
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
                            <tr><td colspan="6" class="tk-empty-row">در این بازه داده‌ای نیست.</td></tr>
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
            </div>
        </article>
    </main>
</div>
@endsection
