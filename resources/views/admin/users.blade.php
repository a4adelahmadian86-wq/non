@extends('layouts.app')
@section('content')
@php
    $actor = auth()->user();
    $isSuperAdmin = $actor->isAdmin();
    $roleLabels = [
        \App\Models\User::ROLE_ADMIN => 'مدیر اصلی',
        \App\Models\User::ROLE_ORGANIZATION_MANAGER => 'مدیر سازمان',
        \App\Models\User::ROLE_TEAM_SUPERVISOR => 'سرپرست تیم',
        \App\Models\User::ROLE_EMPLOYEE => 'کارمند',
        \App\Models\User::ROLE_OBSERVER => 'ناظر',
        \App\Models\User::ROLE_GUEST => 'مهمان',
    ];
@endphp
<div class="farast-admin" dir="rtl">
    @include('admin.partials.sidebar')ide>

    <main class="farast-admin-main">
        <header class="farast-admin-topbar">
            <div class="farast-admin-heading">
                <div class="farast-admin-kicker"><span></span> USER MANAGEMENT</div>
                <h1>مدیریت کاربران</h1>
                <p>فهرست کامل با جستجو، فیلتر نقش/وضعیت، تغییر نقش و کنترل دسترسی — فقط درون دامنهٔ مجاز شما.</p>
            </div>
            <div class="farast-admin-top-actions">
                <a href="{{ route('admin.index') }}" class="admin-ghost"><i class="fa-solid fa-gauge-high"></i> بازگشت به داشبورد</a>
                <div class="farast-admin-user"><span>{{ mb_substr($actor->name ?: 'م', 0, 1) }}</span><div><b>{{ $actor->name }}</b><small>{{ $roleLabels[$actor->role] ?? $actor->role }}</small></div></div>
            </div>
        </header>

        @if(session('status'))
            <div class="farast-admin-toast"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif

        <section id="users-directory" class="admin-section admin-panel-wide">
            <div class="admin-panel-head">
                <div><span class="panel-eyebrow">DIRECTORY</span><h2>فهرست کاربران</h2><p>عملیات نوشتاری (مسدودی، نقش، capabilities) تابع RBAC+Scope است و خارج از دامنهٔ شما مسدود می‌شود.</p></div>
                <span class="count-chip">{{ number_format($users->total()) }} نتیجه</span>
            </div>

            <form method="get" action="{{ route('admin.users') }}" class="settings-modern" style="margin-bottom:18px">
                <label class="modern-field"><span><i class="fa-solid fa-magnifying-glass"></i> جستجو</span><input name="q" value="{{ $filters['q'] }}" placeholder="نام، موبایل یا ایمیل"></label>
                <label class="modern-field"><span><i class="fa-solid fa-user-tag"></i> نقش</span>
                    <select name="role">
                        <option value="">همه نقش‌ها</option>
                        @foreach(\App\Models\User::DASHBOARD_ROLES as $r)<option value="{{ $r }}" @selected($filters['role']===$r)>{{ $roleLabels[$r] ?? $r }}</option>@endforeach
                    </select>
                </label>
                <label class="modern-field"><span><i class="fa-solid fa-toggle-half"></i> وضعیت</span>
                    <select name="status">
                        <option value="">همه</option>
                        <option value="active" @selected($filters['status']==='active')>فعال</option>
                        <option value="blocked" @selected($filters['status']==='blocked')>مسدود</option>
                    </select>
                </label>
                <div class="settings-submit" style="grid-column:1/-1"><button class="admin-primary"><i class="fa-solid fa-filter"></i> اعمال فیلتر</button></div>
            </form>

            <div class="user-modern-list">
                @forelse($users as $u)
                    @php $cap = app(\App\Services\CapabilityService::class)->forUser($u); @endphp
                    <article class="user-modern-row">
                        <div class="user-identity">
                            <span class="user-modern-avatar">{{ mb_substr($u->name ?: 'ک', 0, 1) }}</span>
                            <div>
                                <b>{{ $u->name ?: 'کاربر بدون نام' }}</b>
                                <small>{{ $u->mobile }} @if($u->email) · {{ $u->email }} @endif @if($u->organization) · {{ $u->organization->name }} @endif</small>
                            </div>
                        </div>
                        <div class="user-state">
                            <span class="state-badge {{ $u->is_blocked ? 'blocked' : 'active' }}">{{ $u->is_blocked ? 'مسدود' : 'فعال' }}</span>
                            <span class="role-badge">{{ $roleLabels[$u->role] ?? $u->role }}</span>
                        </div>
                        @if($isSuperAdmin && !$u->isAdmin())
                            <form method="post" action="{{ route('admin.users.role', $u) }}" style="display:inline">@csrf
                                <label style="display:flex;gap:6px;align-items:center">
                                    <select name="role" style="padding:6px 8px;border-radius:8px;border:1px solid #d7dbe7">
                                        @foreach(\App\Models\User::DASHBOARD_ROLES as $r)<option value="{{ $r }}" @selected($u->role===$r)>{{ $roleLabels[$r] ?? $r }}</option>@endforeach
                                    </select>
                                    <button class="save-mini" title="ذخیره نقش"><i class="fa-solid fa-user-tag"></i></button>
                                </label>
                            </form>
                        @endif
                        @if(!$u->isAdmin())
                            <form method="post" action="{{ route('admin.user.capabilities', $u) }}" class="user-modern-controls">
                                @csrf
                                <div class="user-toggles">@foreach(['can_type'=>'تایپ','can_ai'=>'AI','can_voice'=>'صوت','can_export_docx'=>'Word','can_export_pdf'=>'PDF','can_support'=>'پشتیبانی'] as $key=>$label)<label title="{{ $label }}"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" {{ $cap[$key] ? 'checked' : '' }}><span>{{ $label }}</span></label>@endforeach</div>
                                <div class="user-limits"><label>رایگان/هفته<input type="number" name="weekly_free_pages" value="{{ $cap['weekly_free_pages'] }}" min="0"></label><label>MB<input type="number" name="max_file_mb" value="{{ $cap['max_file_mb'] }}" min="1"></label><label>AI/روز<input type="number" name="daily_ai_requests" value="{{ $cap['daily_ai_requests'] }}" min="0"></label></div>
                                <div class="user-actions"><button class="save-mini"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button><button type="submit" formaction="{{ route('admin.user.toggle', $u) }}" formmethod="post" class="block-mini">{{ $u->is_blocked ? 'رفع مسدودی' : 'مسدود کردن' }}</button></div>
                            </form>
                        @else
                            <div class="master-unlimited"><i class="fa-solid fa-infinity"></i> مدیر اصلی · نامحدود</div>
                        @endif
                    </article>
                @empty
                    <div class="admin-empty">کاربری با این فیلترها در دامنهٔ دسترسی شما یافت نشد.</div>
                @endforelse
            </div>

            {{ $users->links() }}
        </section>
    </main>
</div>
@endsection
