@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261020ann">
@php $pinned = $pinned ?? collect(); @endphp
<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('announcements') }}" class="tk-crumb-strong">اعلانات</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <span>اعلانات و اطلاع‌رسانی</span>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>اعلانات داشبورد</h1>
                    <p>اطلاعیه‌های سرویس، تغییرات و پیام‌های مهم — فقط برای کاربران واردشده</p>
                </hgroup>
            </div>
            <div class="tk-divider"></div>

            @if($pinned->isNotEmpty())
                <div style="display:grid;gap:10px;margin-bottom:16px">
                    @foreach($pinned as $announcement)
                        <div class="tk-notice" style="border-color: {{ $announcement->type === 'danger' ? '#fecaca' : ($announcement->type === 'warning' ? '#fde68a' : '#bfdbfe') }}">
                            <div class="tk-notice-text">
                                <span class="tk-notice-tag">{{ $announcement->typeLabel() }} · پین</span>
                                <p><b>{{ $announcement->title }}</b> — {{ \Illuminate\Support\Str::limit($announcement->body, 160) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="tk-table-wrap">
                <div style="display:grid;gap:12px;padding:4px 0 12px">
                    @forelse($announcements as $announcement)
                        <article style="display:flex;gap:14px;align-items:flex-start;padding:14px 16px;border:1px solid var(--sd-line, #e2e8f0);border-radius:14px;background:#fff">
                            <div style="width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#eef4ff;color:#1769ff;flex-shrink:0">
                                <i class="fa-solid {{ $announcement->icon() }}"></i>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px;font-size:.78rem;color:#64748b">
                                    <span>{{ $announcement->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span>
                                    <span>·</span>
                                    <span>{{ $announcement->typeLabel() }}</span>
                                    @if($announcement->is_pinned)
                                        <span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:999px;font-weight:700"><i class="fa-solid fa-thumbtack"></i> پین</span>
                                    @endif
                                    <span style="background:#f1f5f9;padding:2px 8px;border-radius:999px">{{ $announcement->audienceLabel() }}</span>
                                </div>
                                <h2 style="margin:0 0 6px;font-size:1.05rem;color:#0f172a">{{ $announcement->title }}</h2>
                                <p style="margin:0;color:#475569;line-height:1.75;white-space:pre-wrap">{{ $announcement->body }}</p>
                                @if($announcement->link_url)
                                    <a href="{{ $announcement->link_url }}" target="_blank" rel="noopener" style="display:inline-block;margin-top:10px;color:#1769ff;font-weight:700;font-size:.88rem">
                                        مشاهده لینک مرتبط <i class="fa-solid fa-arrow-up-left-from-square"></i>
                                    </a>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="tk-empty-row" style="text-align:center;padding:40px 16px">
                            <i class="fa-regular fa-bell-slash" style="font-size:1.6rem;color:#94a3b8"></i>
                            <b style="display:block;margin-top:10px">اعلان فعالی برای شما وجود ندارد</b>
                            <span style="color:#64748b;font-size:.9rem">در صورت انتشار اطلاعیه جدید، اینجا و در صندوق اعلان‌ها نمایش داده می‌شود.</span>
                        </div>
                    @endforelse
                </div>
            </div>
            {{ $announcements->links() }}
        </article>
    </main>
</div>
@endsection
