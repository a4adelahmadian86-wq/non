@extends('layouts.app')

@section('content')
@php $filters = $filters ?? []; @endphp
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019ann">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('admin.announcements.index') }}" class="tk-crumb-strong">مدیریت اعلان‌ها</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('admin.index') }}">پنل ادمین</a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('announcements') }}">صفحه عمومی اعلانات</a>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        @if(session('status'))
            <div class="tk-notice" style="margin-bottom:14px">
                <div class="tk-notice-text"><span class="tk-notice-tag">OK</span><p>{{ session('status') }}</p></div>
            </div>
        @endif

        <article class="tk-card" style="margin-bottom:16px">
            <div class="tk-card-head">
                <hgroup>
                    <h1>انتشار اعلان جدید</h1>
                    <p>هماهنگ با صندوق اعلان‌ها، ایمیل و صفحه عمومی اعلانات</p>
                </hgroup>
            </div>

            <form method="post" action="{{ route('admin.announcements.store') }}" class="tk-create-form">
                @csrf
                <div class="tk-row-2">
                    <div class="tk-field-block">
                        <p class="tk-label">عنوان <span class="tk-req">*</span></p>
                        <input class="tk-input" name="title" required maxlength="180" value="{{ old('title') }}" placeholder="عنوان اعلان">
                        @error('title')<p class="tk-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="tk-field-block">
                        <p class="tk-label">نوع</p>
                        <select class="tk-select" name="type">
                            <option value="info" @selected(old('type','info')==='info')>اطلاع‌رسانی</option>
                            <option value="success" @selected(old('type')==='success')>موفقیت</option>
                            <option value="warning" @selected(old('type')==='warning')>هشدار</option>
                            <option value="danger" @selected(old('type')==='danger')>مهم</option>
                        </select>
                    </div>
                </div>

                <div class="tk-row-2">
                    <div class="tk-field-block">
                        <p class="tk-label">مخاطب</p>
                        <select class="tk-select" name="audience">
                            <option value="all" @selected(old('audience','all')==='all')>همه کاربران</option>
                            <option value="users" @selected(old('audience')==='users')>فقط کاربران عادی</option>
                            <option value="admins" @selected(old('audience')==='admins')>فقط مدیران</option>
                        </select>
                    </div>
                    <div class="tk-field-block">
                        <p class="tk-label">لینک مرتبط (اختیاری)</p>
                        <input class="tk-input" type="url" name="link_url" maxlength="500" value="{{ old('link_url') }}" placeholder="https://...">
                        @error('link_url')<p class="tk-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="tk-row-2">
                    <div class="tk-field-block">
                        <p class="tk-label">شروع نمایش</p>
                        <input class="tk-input" type="datetime-local" name="starts_at" value="{{ old('starts_at') }}">
                    </div>
                    <div class="tk-field-block">
                        <p class="tk-label">پایان نمایش</p>
                        <input class="tk-input" type="datetime-local" name="ends_at" value="{{ old('ends_at') }}">
                    </div>
                </div>

                <div class="tk-field-block">
                    <p class="tk-label">متن اعلان <span class="tk-req">*</span></p>
                    <textarea class="tk-textarea" name="body" rows="4" required maxlength="5000" placeholder="متن کامل اعلان">{{ old('body') }}</textarea>
                    @error('body')<p class="tk-error">{{ $message }}</p>@enderror
                </div>

                <div class="tk-chips" style="justify-content:flex-start">
                    <label class="tk-chip is-on"><input type="checkbox" name="is_active" value="1" checked> فعال</label>
                    <label class="tk-chip"><input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned'))> پین</label>
                    <label class="tk-chip is-on"><input type="checkbox" name="notify_in_app" value="1" checked> اعلان درون‌برنامه</label>
                    <label class="tk-chip"><input type="checkbox" name="notify_email" value="1" @checked(old('notify_email'))> ایمیل به مخاطبان</label>
                </div>

                <button type="submit" class="tk-btn-primary tk-submit">انتشار و اطلاع‌رسانی</button>
            </form>
        </article>

        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>لیست اعلان‌ها</h1>
                    <p>فعال/غیرفعال، ارسال مجدد و حذف</p>
                </hgroup>
            </div>

            <form method="get" class="tk-toolbar">
                <select class="tk-select" name="status" style="max-width:160px" onchange="this.form.submit()">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active" @selected(($filters['status'] ?? '')==='active')>فعال</option>
                    <option value="inactive" @selected(($filters['status'] ?? '')==='inactive')>غیرفعال</option>
                </select>
                <select class="tk-select" name="audience" style="max-width:160px" onchange="this.form.submit()">
                    <option value="">همه مخاطبان</option>
                    <option value="all" @selected(($filters['audience'] ?? '')==='all')>همه</option>
                    <option value="users" @selected(($filters['audience'] ?? '')==='users')>کاربران</option>
                    <option value="admins" @selected(($filters['audience'] ?? '')==='admins')>مدیران</option>
                </select>
                <select class="tk-select" name="type" style="max-width:160px" onchange="this.form.submit()">
                    <option value="">همه انواع</option>
                    <option value="info" @selected(($filters['type'] ?? '')==='info')>اطلاع‌رسانی</option>
                    <option value="success" @selected(($filters['type'] ?? '')==='success')>موفقیت</option>
                    <option value="warning" @selected(($filters['type'] ?? '')==='warning')>هشدار</option>
                    <option value="danger" @selected(($filters['type'] ?? '')==='danger')>مهم</option>
                </select>
            </form>

            <div class="tk-table-wrap">
                <table class="tk-table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>نوع</th>
                            <th>مخاطب</th>
                            <th>وضعیت</th>
                            <th>اعلان</th>
                            <th>تاریخ</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($announcements as $a)
                            <tr>
                                <td style="text-align:right">
                                    <strong>{{ $a->title }}</strong>
                                    @if($a->is_pinned)
                                        <span class="tk-new-reply" style="background:#1769ff">پین</span>
                                    @endif
                                    <div class="tk-hint" style="margin-top:4px">{{ \Illuminate\Support\Str::limit($a->body, 80) }}</div>
                                </td>
                                <td>{{ $a->typeLabel() }}</td>
                                <td>{{ $a->audienceLabel() }}</td>
                                <td>
                                    <span class="tk-status">
                                        <span class="tk-dot {{ $a->is_active ? 'tk-dot-answered' : 'tk-dot-closed' }}"></span>
                                        {{ $a->is_active ? 'فعال' : 'خاموش' }}
                                    </span>
                                </td>
                                <td style="font-size:.78rem;color:#64748b">
                                    @if($a->notify_in_app)<div>درون‌برنامه</div>@endif
                                    @if($a->notify_email)<div>ایمیل</div>@endif
                                    @if($a->notified_at)<div>ارسال: {{ $a->notified_at->format('m/d H:i') }}</div>@endif
                                </td>
                                <td>{{ $a->created_at?->format('Y/m/d H:i') }}</td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
                                        <form method="post" action="{{ route('admin.announcements.toggle', $a) }}">@csrf
                                            <button class="tk-btn-ghost" type="submit" title="فعال/غیرفعال"><i class="fa-solid fa-power-off"></i></button>
                                        </form>
                                        <form method="post" action="{{ route('admin.announcements.rebroadcast', $a) }}">@csrf
                                            <button class="tk-btn-ghost" type="submit" title="ارسال مجدد"><i class="fa-solid fa-rotate"></i></button>
                                        </form>
                                        <form method="post" action="{{ route('admin.announcements.destroy', $a) }}" onsubmit="return confirm('حذف این اعلان؟')">@csrf @method('DELETE')
                                            <button class="tk-btn-ghost" type="submit" title="حذف" style="color:#dc2626"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="tk-empty-row">اعلانی ثبت نشده است.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="tk-footer-bar">
                {{ $announcements->links() }}
            </div>
        </article>
    </main>
</div>

<script>
document.querySelectorAll('.tk-chips .tk-chip').forEach(function (chip) {
    var input = chip.querySelector('input[type=checkbox]');
    if (!input) return;
    function sync() { chip.classList.toggle('is-on', input.checked); }
    input.addEventListener('change', sync);
    chip.addEventListener('click', function (e) {
        if (e.target === input) return;
        input.checked = !input.checked;
        sync();
    });
    sync();
});
</script>
@endsection
