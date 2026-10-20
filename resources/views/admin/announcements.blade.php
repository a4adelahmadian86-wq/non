@extends('layouts.app')

@section('content')
@php
    $filters = $filters ?? [];
    $stats = $stats ?? ['total' => 0, 'active' => 0, 'pinned' => 0, 'scheduled' => 0];
@endphp
<link rel="stylesheet" href="{{ asset('css/announcements.css') }}?v=20261020pro2">

<div class="an-page an-admin-page" dir="rtl">
    <nav class="an-crumb">
        <a href="{{ route('admin.index') }}">پنل ادمین</a>
        <span class="dot">/</span>
        <strong>مدیریت اعلان‌ها</strong>
        <span class="dot">·</span>
        <a href="{{ route('announcements') }}">نمایش برای کاربران</a>
    </nav>

    <header class="an-hero">
        <div class="an-hero-main">
            <div class="an-hero-mark" aria-hidden="true"><i class="fa-solid fa-bullhorn"></i></div>
            <div>
                <h1>مرکز اعلانات</h1>
                <p>انتشار، زمان‌بندی و اطلاع‌رسانی فقط داخل داشبورد — بدون نمایش در فرانت عمومی.</p>
            </div>
        </div>
    </header>

    @if(session('status'))
        <div class="an-flash">{{ session('status') }}</div>
    @endif

    <div class="an-stats" style="grid-template-columns:repeat(5,1fr)">
        <div class="an-stat"><b>{{ $stats['total'] }}</b><span>فعال در لیست</span></div>
        <div class="an-stat"><b>{{ $stats['active'] }}</b><span>روشن</span></div>
        <div class="an-stat"><b>{{ $stats['pinned'] }}</b><span>پین‌شده</span></div>
        <div class="an-stat"><b>{{ $stats['scheduled'] }}</b><span>زمان‌بندی‌شده</span></div>
        <div class="an-stat"><b>{{ $stats['archived'] ?? 0 }}</b><span>آرشیو</span></div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
        <a href="{{ route('admin.announcements.index') }}" class="an-btn an-btn-sm {{ ($tab ?? 'active') !== 'archive' ? 'an-btn-primary' : 'an-btn-ghost' }}">
            <i class="fa-solid fa-inbox"></i> جاری
        </a>
        <a href="{{ route('admin.announcements.index', ['tab' => 'archive']) }}" class="an-btn an-btn-sm {{ ($tab ?? '') === 'archive' ? 'an-btn-primary' : 'an-btn-ghost' }}">
            <i class="fa-solid fa-box-archive"></i> آرشیو ({{ $stats['archived'] ?? 0 }})
        </a>
    </div>

    <article class="an-surface">
        <div class="an-surface-head">
            <h2><i class="fa-solid fa-pen-to-square"></i> انتشار اعلان جدید</h2>
            <span>فقط داشبورد · درون‌برنامه و ایمیل اختیاری</span>
        </div>
        <div class="an-surface-body">
            <form method="post" action="{{ route('admin.announcements.store') }}" class="an-form-grid">
                @csrf
                <div class="an-field">
                    <label class="an-label">عنوان <span class="req">*</span></label>
                    <input class="an-input" name="title" required maxlength="180" value="{{ old('title') }}" placeholder="مثلاً: به‌روزرسانی سرویس OCR">
                    @error('title')<p class="an-error">{{ $message }}</p>@enderror
                </div>
                <div class="an-field">
                    <label class="an-label">نوع</label>
                    <select class="an-select" name="type">
                        <option value="info" @selected(old('type', 'info') === 'info')>اطلاع‌رسانی</option>
                        <option value="success" @selected(old('type') === 'success')>موفقیت / خبر خوب</option>
                        <option value="warning" @selected(old('type') === 'warning')>هشدار</option>
                        <option value="danger" @selected(old('type') === 'danger')>مهم / فوری</option>
                    </select>
                </div>
                <div class="an-field">
                    <label class="an-label">مخاطب</label>
                    <select class="an-select" name="audience">
                        <option value="all" @selected(old('audience', 'all') === 'all')>همه کاربران و مدیران</option>
                        <option value="users" @selected(old('audience') === 'users')>فقط کاربران عادی</option>
                        <option value="admins" @selected(old('audience') === 'admins')>فقط مدیران</option>
                    </select>
                </div>
                <div class="an-field">
                    <label class="an-label">لینک مرتبط (اختیاری)</label>
                    <input class="an-input" type="url" name="link_url" maxlength="500" value="{{ old('link_url') }}" placeholder="https://...">
                    @error('link_url')<p class="an-error">{{ $message }}</p>@enderror
                </div>
                <div class="an-field">
                    <label class="an-label">شروع نمایش</label>
                    <input class="an-input" type="datetime-local" name="starts_at" value="{{ old('starts_at') }}">
                </div>
                <div class="an-field">
                    <label class="an-label">پایان نمایش</label>
                    <input class="an-input" type="datetime-local" name="ends_at" value="{{ old('ends_at') }}">
                    @error('ends_at')<p class="an-error">{{ $message }}</p>@enderror
                </div>
                <div class="an-field full">
                    <label class="an-label">متن اعلان <span class="req">*</span></label>
                    <textarea class="an-textarea" name="body" required maxlength="5000" placeholder="متن کامل اعلان برای نمایش در داشبورد و صندوق اعلان‌ها">{{ old('body') }}</textarea>
                    @error('body')<p class="an-error">{{ $message }}</p>@enderror
                </div>

                <div class="an-checks">
                    <label class="an-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                        فعال
                    </label>
                    <label class="an-check">
                        <input type="hidden" name="is_pinned" value="0">
                        <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned'))>
                        پین در داشبورد
                    </label>
                    <label class="an-check">
                        <input type="hidden" name="notify_in_app" value="0">
                        <input type="checkbox" name="notify_in_app" value="1" @checked(old('notify_in_app', true))>
                        اعلان درون‌برنامه
                    </label>
                    <label class="an-check">
                        <input type="hidden" name="notify_email" value="0">
                        <input type="checkbox" name="notify_email" value="1" @checked(old('notify_email'))>
                        ایمیل به مخاطبان
                    </label>
                </div>

                <div class="an-actions-row">
                    <button type="submit" class="an-btn an-btn-primary">
                        <i class="fa-solid fa-paper-plane"></i>
                        انتشار و اطلاع‌رسانی
                    </button>
                </div>
            </form>
        </div>
    </article>

    <article class="an-surface">
        <div class="an-surface-head">
            <h2><i class="fa-solid fa-list"></i> لیست اعلان‌ها</h2>
            <span>فعال‌سازی · ارسال مجدد · حذف</span>
        </div>
        <div class="an-surface-body">
            <form method="get" action="{{ route('admin.announcements.index') }}" class="an-filters" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                @if(($tab ?? '') === 'archive')<input type="hidden" name="tab" value="archive">@endif
                <input class="an-input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="جستجو در عنوان یا متن..." style="max-width:220px" onchange="this.form.submit()">
                <select class="an-select" name="status" onchange="this.form.submit()">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>فعال</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>غیرفعال</option>
                    <option value="pinned" @selected(($filters['status'] ?? '') === 'pinned')>پین‌شده</option>
                    <option value="scheduled" @selected(($filters['status'] ?? '') === 'scheduled')>زمان‌بندی‌شده</option>
                </select>
                <select class="an-select" name="audience" onchange="this.form.submit()">
                    <option value="">همه مخاطبان</option>
                    <option value="all" @selected(($filters['audience'] ?? '') === 'all')>همه</option>
                    <option value="users" @selected(($filters['audience'] ?? '') === 'users')>کاربران</option>
                    <option value="admins" @selected(($filters['audience'] ?? '') === 'admins')>مدیران</option>
                </select>
                <select class="an-select" name="type" onchange="this.form.submit()">
                    <option value="">همه انواع</option>
                    <option value="info" @selected(($filters['type'] ?? '') === 'info')>اطلاع‌رسانی</option>
                    <option value="success" @selected(($filters['type'] ?? '') === 'success')>موفقیت</option>
                    <option value="warning" @selected(($filters['type'] ?? '') === 'warning')>هشدار</option>
                    <option value="danger" @selected(($filters['type'] ?? '') === 'danger')>مهم</option>
                </select>
                <button type="submit" class="an-btn an-btn-ghost an-btn-sm">اعمال</button>
            </form>

            <div class="an-table-wrap">
                <table class="an-table">
                    <thead>
                        <tr>
                            <th>عنوان</th>
                            <th>نوع</th>
                            <th>مخاطب</th>
                            <th>وضعیت</th>
                            <th>کانال</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($announcements as $a)
                            @php
                                $sched = $a->starts_at && $a->starts_at->isFuture();
                                $expired = $a->ends_at && $a->ends_at->isPast();
                            @endphp
                            <tr>
                                <td style="min-width:200px;text-align:right">
                                    <span class="an-title-cell">{{ $a->title }}</span>
                                    @if($a->is_pinned)
                                        <span class="an-tag an-tag-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                                    @endif
                                    <div class="an-excerpt">{{ \Illuminate\Support\Str::limit($a->body, 100) }}</div>
                                </td>
                                <td>
                                    <div class="an-type-{{ $a->type }}" style="display:flex;align-items:center;gap:8px">
                                        <span class="an-mini-icon" aria-hidden="true"><i class="fa-solid {{ $a->icon() }}"></i></span>
                                        <span class="an-tag an-tag-{{ $a->type }}">{{ $a->typeLabel() }}</span>
                                    </div>
                                </td>
                                <td>{{ $a->audienceLabel() }}</td>
                                <td>
                                    @if($expired)
                                        <span class="an-tag an-tag-muted">منقضی</span>
                                    @elseif($sched)
                                        <span class="an-tag an-tag-sched">زمان‌بندی</span>
                                    @elseif($a->is_active)
                                        <span class="an-tag an-tag-on">فعال</span>
                                    @else
                                        <span class="an-tag an-tag-off">خاموش</span>
                                    @endif
                                </td>
                                <td style="font-size:.78rem;color:#64748b;white-space:nowrap">
                                    @if($a->notify_in_app)<div><i class="fa-solid fa-bell"></i> درون‌برنامه</div>@endif
                                    @if($a->notify_email)<div><i class="fa-solid fa-envelope"></i> ایمیل</div>@endif
                                    @if($a->notified_at)
                                        <div>ارسال: {{ $a->notified_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</div>
                                    @else
                                        <div>هنوز ارسال نشده</div>
                                    @endif
                                </td>
                                <td style="font-size:.78rem;white-space:nowrap">
                                    <div>{{ $a->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</div>
                                    @if($a->starts_at)
                                        <div style="color:#64748b">از {{ $a->starts_at->timezone(config('app.timezone'))->format('m/d H:i') }}</div>
                                    @endif
                                    @if($a->ends_at)
                                        <div style="color:#64748b">تا {{ $a->ends_at->timezone(config('app.timezone'))->format('m/d H:i') }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="an-row-actions">
                                        <button type="button" class="an-btn an-btn-ghost an-btn-sm" data-edit-toggle="{{ $a->id }}" title="ویرایش">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="post" action="{{ route('admin.announcements.toggle', $a) }}">
                                            @csrf
                                            <button class="an-btn an-btn-ghost an-btn-sm" type="submit" title="فعال/غیرفعال">
                                                <i class="fa-solid fa-power-off"></i>
                                            </button>
                                        </form>
                                        <form method="post" action="{{ route('admin.announcements.rebroadcast', $a) }}">
                                            @csrf
                                            <button class="an-btn an-btn-ghost an-btn-sm" type="submit" title="ارسال مجدد">
                                                <i class="fa-solid fa-rotate"></i>
                                            </button>
                                        </form>
                                        
                                        @if($a->isArchived())
                                        <form method="post" action="{{ route('admin.announcements.restore', $a) }}">
                                            @csrf
                                            <button type="submit" class="an-btn an-btn-ghost an-btn-sm" title="بازگردانی از آرشیو"><i class="fa-solid fa-rotate-left"></i></button>
                                        </form>
                                        @else
                                        <form method="post" action="{{ route('admin.announcements.archive', $a) }}" onsubmit="return confirm('انتقال به آرشیو؟')">
                                            @csrf
                                            <button type="submit" class="an-btn an-btn-ghost an-btn-sm" title="آرشیو"><i class="fa-solid fa-box-archive"></i></button>
                                        </form>
                                        @endif
<form method="post" action="{{ route('admin.announcements.destroy', $a) }}" onsubmit="return confirm('حذف این اعلان؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="an-btn an-btn-ghost an-btn-sm an-btn-danger" type="submit" title="حذف">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="an-edit-{{ $a->id }}" class="an-edit-row" hidden>
                                <td colspan="7" style="background:#f8fafc;padding:16px">
                                    <form method="post" action="{{ route('admin.announcements.update', $a) }}" class="an-form-grid">
                                        @csrf
                                        @method('PUT')
                                        <div class="an-field">
                                            <label class="an-label">عنوان</label>
                                            <input class="an-input" name="title" required maxlength="180" value="{{ old('title', $a->title) }}">
                                        </div>
                                        <div class="an-field">
                                            <label class="an-label">نوع</label>
                                            <select class="an-select" name="type">
                                                <option value="info" @selected(old('type', $a->type)==='info')>اطلاع‌رسانی</option>
                                                <option value="success" @selected(old('type', $a->type)==='success')>موفقیت</option>
                                                <option value="warning" @selected(old('type', $a->type)==='warning')>هشدار</option>
                                                <option value="danger" @selected(old('type', $a->type)==='danger')>مهم</option>
                                            </select>
                                        </div>
                                        <div class="an-field">
                                            <label class="an-label">مخاطب</label>
                                            <select class="an-select" name="audience">
                                                <option value="all" @selected(old('audience', $a->audience ?? 'all')==='all')>همه</option>
                                                <option value="users" @selected(old('audience', $a->audience)==='users')>کاربران</option>
                                                <option value="admins" @selected(old('audience', $a->audience)==='admins')>مدیران</option>
                                            </select>
                                        </div>
                                        <div class="an-field">
                                            <label class="an-label">لینک</label>
                                            <input class="an-input" type="url" name="link_url" maxlength="500" value="{{ old('link_url', $a->link_url) }}">
                                        </div>
                                        <div class="an-field">
                                            <label class="an-label">شروع</label>
                                            <input class="an-input" type="datetime-local" name="starts_at" value="{{ old('starts_at', optional($a->starts_at)->format('Y-m-d\TH:i')) }}">
                                        </div>
                                        <div class="an-field">
                                            <label class="an-label">پایان</label>
                                            <input class="an-input" type="datetime-local" name="ends_at" value="{{ old('ends_at', optional($a->ends_at)->format('Y-m-d\TH:i')) }}">
                                        </div>
                                        <div class="an-field full">
                                            <label class="an-label">متن</label>
                                            <textarea class="an-textarea" name="body" required maxlength="5000">{{ old('body', $a->body) }}</textarea>
                                        </div>
                                        <div class="an-checks">
                                            <label class="an-check">
                                                <input type="hidden" name="is_active" value="0">
                                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $a->is_active))> فعال
                                            </label>
                                            <label class="an-check">
                                                <input type="hidden" name="is_pinned" value="0">
                                                <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $a->is_pinned))> پین
                                            </label>
                                            <label class="an-check">
                                                <input type="hidden" name="notify_in_app" value="0">
                                                <input type="checkbox" name="notify_in_app" value="1" @checked(old('notify_in_app', $a->notify_in_app))> درون‌برنامه
                                            </label>
                                            <label class="an-check">
                                                <input type="hidden" name="notify_email" value="0">
                                                <input type="checkbox" name="notify_email" value="1" @checked(old('notify_email', $a->notify_email))> ایمیل
                                            </label>
                                        </div>
                                        <div class="an-actions-row">
                                            <button type="submit" class="an-btn an-btn-primary an-btn-sm"><i class="fa-solid fa-check"></i> ذخیره تغییرات</button>
                                            <button type="button" class="an-btn an-btn-ghost an-btn-sm" data-edit-toggle="{{ $a->id }}">انصراف</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="an-empty">
                                        <i class="fa-regular fa-bell-slash"></i>
                                        <b>اعلانی ثبت نشده است</b>
                                        <span>از فرم بالا اولین اعلان را منتشر کنید.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(method_exists($announcements, 'links'))
            <div class="an-footer-bar">{{ $announcements->links() }}</div>
        @endif
    </article>
</div>

<script>
document.querySelectorAll('.an-check').forEach(function (chip) {
    var boxes = chip.querySelectorAll('input[type=checkbox]');
    var box = boxes[boxes.length - 1];
    if (!box) return;
    function sync() { chip.classList.toggle('is-on', box.checked); }
    box.addEventListener('change', sync);
    sync();
});
document.querySelectorAll('[data-edit-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-edit-toggle');
        var row = document.getElementById('an-edit-' + id);
        if (!row) return;
        row.hidden = !row.hidden;
    });
});
</script>
@endsection
