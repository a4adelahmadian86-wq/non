@extends('layouts.admin')

@section('admin-content')
@php
    $roleLabels = [
        'admin' => 'مدیر اصلی',
        'user' => 'کاربر',
        'organization_manager' => 'مدیر سازمان',
        'team_supervisor' => 'سرپرست تیم',
        'employee' => 'کارمند',
        'observer' => 'ناظر',
        'guest' => 'مهمان',
    ];
    $roleDescriptions = [
        'admin' => 'دسترسی کامل سیستمی؛ مجوزهای این نقش از این صفحه قابل تغییر نیست.',
        'user' => 'دسترسی پایه برای استفاده شخصی از فضای کاری.',
        'organization_manager' => 'مدیریت کاربران، تیم‌ها و منابع در محدوده سازمان.',
        'team_supervisor' => 'نظارت بر اعضا و گردش کار تیم تحت مدیریت.',
        'employee' => 'انجام فعالیت‌های مجاز روزمره در فضای کاری.',
        'observer' => 'مشاهده اطلاعات مجاز بدون دسترسی مدیریتی.',
        'guest' => 'دسترسی محدود و حداقلی به بخش‌های مجاز.',
    ];
    $groupLabels = [
        'dashboard' => 'داشبورد',
        'users' => 'کاربران',
        'documents' => 'اسناد و فایل‌ها',
        'projects' => 'پروژه‌ها',
        'ai' => 'هوش مصنوعی',
        'ocr' => 'استخراج متن (OCR)',
        'voice' => 'قابلیت‌های صوتی',
        'billing' => 'صورتحساب و اشتراک',
        'finance' => 'امور مالی',
        'support' => 'پشتیبانی',
        'settings' => 'تنظیمات',
        'integrations' => 'یکپارچه‌سازی‌ها',
        'audit' => 'ممیزی',
        'access' => 'نقش‌ها و دسترسی‌ها',
        'organization' => 'سازمان',
        'team' => 'تیم',
        'workflows' => 'گردش‌کارها',
        'reports' => 'گزارش‌ها',
        'system' => 'سامانه',
    ];
    $permissionGroups = $permissions->groupBy('group');
@endphp

<div class="role-access-page" dir="rtl">
    <style>
        .role-access-page{max-width:1440px;margin:0 auto;padding:28px 24px 56px;color:#17233d;font-family:Vazirmatn,IRANSansWeb_Medium,Tahoma,sans-serif}
        .role-access-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:26px 30px;border:1px solid #e5eaf3;border-radius:22px;background:radial-gradient(circle at 8% 10%,rgba(37,199,238,.12),transparent 28%),linear-gradient(120deg,#fff,#f6f8ff);box-shadow:0 14px 40px rgba(16,35,70,.06)}
        .role-access-eyebrow{font-size:10px;letter-spacing:1.5px;font-weight:800;color:#647797}
        .role-access-hero h1{font-size:27px;margin:8px 0 6px;letter-spacing:-.6px}
        .role-access-hero p{font-size:12px;line-height:2;color:#71809a;margin:0;max-width:760px}
        .role-access-back{white-space:nowrap;text-decoration:none;background:#10264c;color:#fff;border-radius:11px;padding:11px 15px;font-size:11px}
        .role-access-notice{display:flex;align-items:flex-start;gap:11px;margin:16px 0;padding:14px 16px;border:1px solid #dce8fb;border-radius:14px;background:#f3f7ff;color:#45628c;font-size:11px;line-height:1.9}
        .role-access-notice strong{display:block;color:#233d68;margin-bottom:2px}
        .role-access-role{margin-top:18px;padding:20px;border:1px solid #e6ebf3;border-radius:19px;background:#fff;box-shadow:0 10px 30px rgba(16,35,70,.045)}
        .role-access-role-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:17px}
        .role-access-role-title{display:flex;align-items:center;gap:11px}
        .role-access-role-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:#edf3ff;color:#2867c9;font-size:18px}
        .role-access-role h2{font-size:16px;margin:0 0 4px}
        .role-access-role-head p{font-size:10px;color:#8491a5;margin:0;line-height:1.8}
        .role-access-count{white-space:nowrap;border-radius:30px;padding:7px 11px;background:#f0f4fb;color:#526784;font-size:10px}
        .role-access-locked{display:flex;align-items:center;gap:9px;padding:13px 15px;border:1px solid #f1e4c9;border-radius:12px;background:#fff9ed;color:#8a6528;font-size:11px}
        .role-access-tools{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 15px}
        .role-access-search{flex:1 1 260px;min-width:220px;position:relative}
        .role-access-search input{width:100%;height:42px;padding:0 14px;border:1px solid #dfe6f0;border-radius:11px;background:#fbfcfe;font:11px Vazirmatn,IRANSansWeb_Medium,Tahoma,sans-serif;outline:0}
        .role-access-search input:focus{border-color:#80a9e8;box-shadow:0 0 0 3px rgba(40,103,201,.09)}
        .role-access-summary{font-size:10px;color:#72829a}
        .role-access-groups{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .role-access-group{min-width:0;padding:14px;border:1px solid #e9edf4;border-radius:14px;background:#fcfdff}
        .role-access-group-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
        .role-access-group-head h3{font-size:12px;margin:0;color:#2b3b57}
        .role-access-group-actions{display:flex;gap:5px}
        .role-access-group-actions button{border:0;background:transparent;color:#2867c9;font:9px Vazirmatn,IRANSansWeb_Medium,Tahoma,sans-serif;cursor:pointer;padding:4px}
        .role-access-permission{display:flex;align-items:center;gap:9px;padding:9px 8px;border-top:1px solid #eef1f6;cursor:pointer}
        .role-access-permission:first-of-type{border-top:0}
        .role-access-permission input{width:16px;height:16px;flex:0 0 auto;accent-color:#2867c9}
        .role-access-permission span{display:block;min-width:0;font-size:10px;color:#35445f}
        .role-access-permission small{display:block;margin-top:2px;color:#9aa5b5;font-size:8px;direction:ltr;text-align:right}
        .role-access-permission.is-hidden{display:none}
        .role-access-empty{display:none;padding:18px;text-align:center;color:#8290a5;font-size:11px}
        .role-access-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;padding-top:15px;border-top:1px solid #edf0f5}
        .role-access-actions p{font-size:9px;color:#8996aa;margin:0}
        .role-access-save{border:0;border-radius:10px;background:linear-gradient(135deg,#1769ff,#3d82f4);color:#fff;padding:11px 18px;font:11px Vazirmatn,IRANSansWeb_Medium,Tahoma,sans-serif;cursor:pointer;box-shadow:0 8px 18px rgba(23,105,255,.18)}
        .role-access-save:hover{filter:brightness(1.04)}
        @media(max-width:800px){.role-access-page{padding:16px 12px 36px}.role-access-hero{padding:20px;flex-direction:column}.role-access-hero h1{font-size:22px}.role-access-role{padding:15px}.role-access-groups{grid-template-columns:1fr}}
        @media(max-width:480px){.role-access-role-head{flex-direction:column}.role-access-count{align-self:flex-start}.role-access-actions{align-items:stretch;flex-direction:column}.role-access-save{width:100%}}
    </style>

    <header class="role-access-hero">
        <div>
            <span class="role-access-eyebrow">ACCESS CONTROL / RBAC</span>
            <h1>مدیریت نقش‌ها و مجوزها</h1>
            <p>دسترسی هر نقش را بر اساس مسئولیت واقعی آن تنظیم کنید. تغییرات این صفحه در سطح سرور ذخیره می‌شود و محدودیت‌های دامنه سازمانی همچنان اعمال خواهند شد.</p>
        </div>
        <a class="role-access-back" href="{{ route('admin.index') }}">بازگشت به مرکز عملیات</a>
    </header>

    <div class="role-access-notice" role="note">
        <span aria-hidden="true">ⓘ</span>
        <div><strong>اصل حداقل دسترسی</strong>فقط مجوزهای موردنیاز هر نقش را فعال کنید. نقش مدیر اصلی برای حفاظت از کنترل سراسری سامانه، از این صفحه قابل ویرایش نیست.</div>
    </div>

    @if(session('status'))
        <div class="role-access-notice" role="status" style="background:#effaf4;border-color:#d5efdf;color:#28784b">{{ session('status') }}</div>
    @endif

    @foreach($roles as $role)
        @php
            $rolePermissionIds = $rolePermissions->where('role', $role)->pluck('permission_id')->all();
            $rolePermissionCount = count($rolePermissionIds);
            $roleLabel = $roleLabels[$role] ?? $role;
            $roleDescription = $roleDescriptions[$role] ?? 'دسترسی‌های این نقش را متناسب با مسئولیت آن تنظیم کنید.';
        @endphp
        <section class="role-access-role" aria-labelledby="role-title-{{ $loop->index }}">
            <div class="role-access-role-head">
                <div class="role-access-role-title">
                    <span class="role-access-role-icon" aria-hidden="true">{{ $role === 'admin' ? '♛' : '◈' }}</span>
                    <div>
                        <h2 id="role-title-{{ $loop->index }}">{{ $roleLabel }}</h2>
                        <p>{{ $roleDescription }}</p>
                    </div>
                </div>
                <span class="role-access-count">{{ $role === 'admin' ? 'دسترسی کامل' : number_format($rolePermissionCount).' مجوز فعال' }}</span>
            </div>

            @if($role === 'admin')
                <div class="role-access-locked"><span aria-hidden="true">🔒</span>این نقش دسترسی سراسری دارد و برای جلوگیری از قفل‌شدن مدیریت سامانه، از این بخش تغییر نمی‌کند.</div>
            @else
                <form method="post" action="{{ route('admin.access.roles.update', $role) }}" data-role-access-form>
                    @csrf
                    <div class="role-access-tools">
                        <label class="role-access-search">
                            <input type="search" placeholder="جستجو در مجوزها..." aria-label="جستجو در مجوزهای {{ $roleLabel }}" data-permission-search>
                        </label>
                        <span class="role-access-summary"><b data-selected-count>{{ $rolePermissionCount }}</b> مجوز انتخاب شده</span>
                    </div>

                    <div class="role-access-groups">
                        @foreach($permissionGroups as $group => $groupPermissions)
                            <section class="role-access-group" data-permission-group>
                                <div class="role-access-group-head">
                                    <h3>{{ $groupLabels[$group] ?? $group }}</h3>
                                    <div class="role-access-group-actions">
                                        <button type="button" data-group-action="select">انتخاب همه</button>
                                        <button type="button" data-group-action="clear">پاک‌کردن</button>
                                    </div>
                                </div>
                                @foreach($groupPermissions as $permission)
                                    <label class="role-access-permission" data-permission-row>
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->key }}" @checked(in_array($permission->id, $rolePermissionIds, true))>
                                        <span>{{ $permission->label }}<small>{{ $permission->key }}</small></span>
                                    </label>
                                @endforeach
                            </section>
                        @endforeach
                    </div>
                    <div class="role-access-empty" data-permission-empty>مجوزی با این عبارت پیدا نشد.</div>
                    <div class="role-access-actions">
                        <p>تغییرات فقط پس از ذخیره اعمال می‌شوند. مجوزهای انتخاب‌نشده از این نقش حذف خواهند شد.</p>
                        <button class="role-access-save" type="submit">ذخیره مجوزهای {{ $roleLabel }}</button>
                    </div>
                </form>
            @endif
        </section>
    @endforeach
</div>

<script>
document.querySelectorAll('[data-role-access-form]').forEach((form) => {
    const search = form.querySelector('[data-permission-search]');
    const rows = Array.from(form.querySelectorAll('[data-permission-row]'));
    const groups = Array.from(form.querySelectorAll('[data-permission-group]'));
    const count = form.querySelector('[data-selected-count]');
    const empty = form.querySelector('[data-permission-empty]');

    const refresh = () => {
        const query = (search?.value || '').trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const matches = !query || row.textContent.toLocaleLowerCase().includes(query);
            row.classList.toggle('is-hidden', !matches);
            if (matches) visible += 1;
        });
        groups.forEach((group) => {
            const hasVisible = group.querySelector('[data-permission-row]:not(.is-hidden)');
            group.hidden = !hasVisible;
        });
        if (empty) empty.style.display = visible ? 'none' : 'block';
        if (count) count.textContent = form.querySelectorAll('input[name="permissions[]"]:checked').length.toLocaleString('fa-IR');
    };

    search?.addEventListener('input', refresh);
    form.querySelectorAll('[data-group-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const group = button.closest('[data-permission-group]');
            if (!group) return;
            group.querySelectorAll('input[name="permissions[]"]').forEach((checkbox) => {
                if (!checkbox.closest('[data-permission-row]')?.classList.contains('is-hidden')) {
                    checkbox.checked = button.dataset.groupAction === 'select';
                }
            });
            refresh();
        });
    });
    form.addEventListener('change', (event) => {
        if (event.target.matches('input[name="permissions[]"]')) refresh();
    });
    refresh();
});
</script>
@endsection
