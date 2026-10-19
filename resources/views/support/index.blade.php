@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-messenger.css') }}?v=20261019pro">
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019pro">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support') }}" class="tk-crumb-strong">تیکت ها</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>تیکت ها</h1>
                    <p>سؤالی دارید یا به کمک نیاز دارید؟ همین حالا تیکت ارسال کنید. ما کنارتون هستیم!</p>
                </hgroup>
                <a href="{{ route('support.create.form') }}" class="tk-btn-primary">
                    <i class="fa-solid fa-plus"></i>
                    <span>ثبت تیکت جدید</span>
                </a>
            </div>

            <div class="tk-divider"></div>

            <div class="tk-toolbar">
                <form method="get" action="{{ route('support') }}" class="tk-search">
                    @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                    @if(request('per_page'))<input type="hidden" name="per_page" value="{{ request('per_page') }}">@endif
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="جستجو کد یا موضوع" autocomplete="off">
                    <button type="submit" class="tk-search-btn" aria-label="جستجو"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>

                <div class="tk-filter">
                    <button type="button" class="tk-filter-btn" id="statusFilterBtn">
                        <i class="fa-solid fa-sliders"></i>
                        <span>بر اساس :
                            @php
                                $st = request('status', 'all');
                                $stLabel = match($st) {
                                    'open' => 'باز',
                                    'closed' => 'بسته شده',
                                    'answered' => 'پاسخ داده شده',
                                    'user_replied' => 'پاسخ کاربر',
                                    'pending' => 'در حال بررسی',
                                    default => 'بدون فیلتر',
                                };
                            @endphp
                            {{ $stLabel }}
                        </span>
                    </button>
                    <div class="tk-filter-menu" id="statusFilterMenu" hidden>
                        @php $base = array_filter(['q' => request('q'), 'per_page' => request('per_page')]); @endphp
                        <a href="{{ route('support', $base) }}" class="{{ !request('status') || request('status')==='all' ? 'is-active' : '' }}">بدون فیلتر</a>
                        <a href="{{ route('support', $base + ['status' => 'open']) }}" class="{{ request('status')==='open' ? 'is-active' : '' }}"><span class="tk-dot tk-dot-open"></span>باز</a>
                        <a href="{{ route('support', $base + ['status' => 'answered']) }}" class="{{ request('status')==='answered' ? 'is-active' : '' }}"><span class="tk-dot tk-dot-answered"></span>پاسخ داده شده</a>
                        <a href="{{ route('support', $base + ['status' => 'user_replied']) }}" class="{{ request('status')==='user_replied' ? 'is-active' : '' }}"><span class="tk-dot tk-dot-user"></span>پاسخ کاربر</a>
                        <a href="{{ route('support', $base + ['status' => 'pending']) }}" class="{{ request('status')==='pending' ? 'is-active' : '' }}"><span class="tk-dot tk-dot-pending"></span>در حال بررسی</a>
                        <a href="{{ route('support', $base + ['status' => 'closed']) }}" class="{{ request('status')==='closed' ? 'is-active' : '' }}"><span class="tk-dot tk-dot-closed"></span>بسته شده</a>
                    </div>
                </div>
            </div>

            <div class="tk-notice">
                <div class="tk-notice-text">
                    <span class="tk-notice-tag">توجه</span>
                    <p>پیشنهاد می‌کنیم پیش از ثبت تیکت، بخش راهنما را مرور کنید. ممکن است پاسخ خود را سریع‌تر بیابید.</p>
                </div>
            </div>

            <div class="tk-table-wrap">
                <table class="tk-table">
                    <thead>
                        <tr>
                            <th>شماره تیکت</th>
                            <th>عنوان</th>
                            <th>تاریخ ایجاد</th>
                            <th>دپارتمان</th>
                            <th>وضعیت</th>
                            <th>آخرین به روزرسانی</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tickets as $t)
                            <tr>
                                <td><a class="tk-row-link" href="{{ route('support.show', $t) }}"></a><span>{{ $t->displayCode() }}</span></td>
                                <td>
                                    <a class="tk-row-link" href="{{ route('support.show', $t) }}"></a>
                                    <span class="tk-truncate">{{ $t->subject }}</span>
                                    @if($t->hasUnreadForCustomer() || $t->status === 'answered')
                                        <span class="tk-new-reply">پاسخ جدید</span>
                                    @endif
                                </td>
                                <td><a class="tk-row-link" href="{{ route('support.show', $t) }}"></a><span>{{ $t->created_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span></td>
                                <td><a class="tk-row-link" href="{{ route('support.show', $t) }}"></a><span>{{ $t->departmentLabel() }}</span></td>
                                <td>
                                    <a class="tk-row-link" href="{{ route('support.show', $t) }}"></a>
                                    <span class="tk-status">
                                        <span class="tk-dot tk-dot-{{ $t->statusColor() }}"></span>
                                        {{ $t->statusLabel() }}
                                    </span>
                                </td>
                                <td><a class="tk-row-link" href="{{ route('support.show', $t) }}"></a><span>{{ optional($t->last_reply_at ?? $t->updated_at)->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span></td>
                                <td><a class="tk-btn-ghost" href="{{ route('support.show', $t) }}">مشاهده</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="tk-empty-row">
                                    هنوز تیکتی ثبت نشده است.
                                    <a href="{{ route('support.create.form') }}" style="color:#1769ff;font-weight:700">ثبت تیکت جدید</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="tk-footer-bar">
                <form method="get" class="tk-per-page">
                    @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
                    @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                    <span>نمایش</span>
                    <select name="per_page" onchange="this.form.submit()">
                        @foreach([5,10,15,20,25,50,100] as $n)
                            <option value="{{ $n }}" @selected((int)request('per_page', 10) === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </form>
                <div class="tk-pagination">{{ $tickets->withQueryString()->links() }}</div>
            </div>
        </article>
    </main>
</div>

@if(session('status'))
    <div class="sd-toast">{{ session('status') }}</div>
@endif

@push('scripts')
<script>
(() => {
  const btn = document.getElementById('statusFilterBtn');
  const menu = document.getElementById('statusFilterMenu');
  btn?.addEventListener('click', (e) => { e.stopPropagation(); if (menu) menu.hidden = !menu.hidden; });
  document.addEventListener('click', () => { if (menu) menu.hidden = true; });
  const toast = document.querySelector('.sd-toast');
  if (toast) setTimeout(() => toast.remove(), 4000);
})();
</script>
@endpush
@endsection
