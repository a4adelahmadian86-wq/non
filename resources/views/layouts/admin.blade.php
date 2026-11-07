@extends('layouts.app')
@section('content')
<div class="farast-admin farast-admin-workspace" dir="rtl">
    @include('admin.partials.sidebar')
    <main class="farast-admin-main">
        <header class="farast-admin-topbar">
            <div class="farast-admin-heading">
                <div class="farast-admin-kicker"><span></span> FARAST CONTROL CENTER</div>
                <p>محیط مدیریت یکپارچه · همه عملیات از یک مرکز</p>
            </div>
            <div class="farast-admin-top-actions">
                <a href="{{ route('admin.index') }}" class="admin-ghost"><i class="fa-solid fa-grid-2"></i> مرکز عملیات</a>
                <a href="{{ route('home') }}" target="_blank" rel="noopener" class="admin-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> مشاهده سایت</a>
                <div class="farast-admin-user">
                    <span>{{ mb_substr(auth()->user()->name ?: 'ف', 0, 1) }}</span>
                    <div><b>{{ auth()->user()->name ?: 'مدیر فراست' }}</b><small>{{ auth()->user()->mobile }}</small></div>
                    <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" aria-label="خروج"><i class="fa-solid fa-right-from-bracket"></i></button></form>
                </div>
            </div>
        </header>
        @yield('admin-content')
    </main>
</div>
@endsection
