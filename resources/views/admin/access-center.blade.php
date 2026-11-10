@extends('layouts.admin')

@section('admin-content')
<div class="access-center" dir="rtl">
    <style>
        .access-center{max-width:1500px;margin:auto;padding:24px;color:#17243b;font-family:Vazirmatn,IRANSansWeb_Medium,Tahoma,sans-serif}
        .ac-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;padding:24px;border:1px solid #e2e9f3;border-radius:18px;background:linear-gradient(125deg,#fff,#f5f8ff)}
        .ac-hero h1{font-size:24px;margin:6px 0}.ac-hero p{font-size:12px;color:#687994;line-height:2;margin:0;max-width:850px}
        .ac-eyebrow{font-size:10px;color:#5076ae;font-weight:800;letter-spacing:1px}
        .ac-link,.ac-button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:9px;padding:10px 14px;background:#174b91;color:#fff;text-decoration:none;font:inherit;font-size:11px;cursor:pointer}
        .ac-link.secondary,.ac-button.secondary{background:#eef3fb;color:#24466f}.ac-button.danger{background:#fff0f0;color:#a52d39}
        .ac-alert{margin:14px 0;padding:12px 15px;border-radius:10px;background:#edf9f1;color:#267044;font-size:12px}
        .ac-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:16px}
        .ac-card{min-width:0;padding:18px;border:1px solid #e4eaf2;border-radius:15px;background:#fff;box-shadow:0 6px 22px #20385b08}
        .ac-card h2{font-size:15px;margin:0 0 12px}.ac-card h3{font-size:12px;margin:15px 0 8px;color:#344967}
        .ac-muted{font-size:10px;color:#7b8ba3;line-height:1.9}
        .ac-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .ac-field{display:flex;flex-direction:column;gap:5px;font-size:10px;color:#52637d}
        .ac-field input,.ac-field select,.ac-field textarea,.ac-search input{width:100%;min-width:0;border:1px solid #dce4ef;border-radius:8px;background:#fbfcfe;padding:9px 10px;font:inherit;font-size:11px;color:#1c2c45}
        .ac-field textarea{min-height:64px;resize:vertical}.ac-field.full{grid-column:1/-1}
        .ac-role{margin-top:14px;border:1px solid #e5ebf3;border-radius:13px;padding:14px}
        .ac-role-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.ac-role-head strong{font-size:13px}.ac-tag{font-size:9px;border-radius:20px;padding:5px 8px;background:#edf3fc;color:#3a5d8d}
        .ac-permissions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin:10px 0}
        .ac-perm{display:flex;gap:7px;align-items:center;padding:7px;border:1px solid #edf0f5;border-radius:8px;font-size:10px;overflow-wrap:anywhere}
        .ac-perm small{display:block;color:#8b98aa;font-size:8px;direction:ltr;text-align:right}
        .ac-user{border-top:1px solid #edf0f5;padding:13px 0}.ac-user:first-child{border-top:0}
        .ac-user-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:9px}.ac-user-head strong{font-size:11px}.ac-user-head small{font-size:9px;color:#8592a5}
        .ac-inline{display:flex;gap:7px;align-items:end;flex-wrap:wrap}.ac-inline .ac-field{flex:1 1 130px}
        .ac-table-wrap{overflow:auto}.ac-table{width:100%;border-collapse:collapse;font-size:10px}.ac-table th,.ac-table td{padding:9px;border-bottom:1px solid #edf0f5;text-align:right;white-space:nowrap}.ac-table th{color:#61738e;background:#f8fafd}
        .ac-search{display:flex;gap:8px;margin:12px 0}.ac-search input{max-width:360px}
        @media(max-width:900px){.ac-grid{grid-template-columns:1fr}.ac-hero{flex-direction:column}.ac-permissions{grid-template-columns:1fr}}
        @media(max-width:560px){.access-center{padding:12px}.ac-form-grid{grid-template-columns:1fr}.ac-field.full{grid-column:auto}.ac-card{padding:13px}}
    </style>

    <header class="ac-hero">
        <div>
            <span class="ac-eyebrow">IDENTITY · ACCESS · GOVERNANCE</span>
            <h1>مرکز مدیریت هویت و دسترسی</h1>
            <p>نقش‌های سفارشی، انتساب چندگانه، محدوده دسترسی، استثناهای مستقیم و تاریخچه تغییرات را از یک مرکز واحد مدیریت کنید. مجوزها در بک‌اند ارزیابی می‌شوند؛ مخفی‌کردن منو به‌تنهایی کنترل امنیتی محسوب نمی‌شود. محدوده نقش سفارشی هرگز از دامنه پایه حساب گسترده‌تر نمی‌شود.</p>
        </div>
        <a class="ac-link secondary" href="{{ route('admin.access') }}">بازگشت به مدیریت نقش‌های پایه</a>
    </header>

    @if(session('status'))<div class="ac-alert" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="ac-alert" style="background:#fff2f1;color:#a33131" role="alert">{{ $errors->first() }}</div>@endif

    <div class="ac-grid">
        <section class="ac-card">
            <h2>ایجاد نقش سفارشی</h2>
            <p class="ac-muted">نقش جدید می‌تواند به‌صورت سراسری یا محدود به یک سازمان، تیم یا مالکیت شخصی تعریف شود.</p>
            <form method="post" action="{{ route('admin.access-center.roles.store') }}">
                @csrf
                <div class="ac-form-grid">
                    <label class="ac-field">نام نقش<input name="name" required maxlength="160" placeholder="مثلاً مدیر محتوای سازمان"></label>
                    <label class="ac-field">محدوده نقش
                        <select name="scope_type" required>
                            <option value="global">سراسری</option><option value="organization">سازمانی</option><option value="team">تیمی</option><option value="own">شخصی</option>
                        </select>
                    </label>
                    <label class="ac-field">شناسه سازمان یا تیم (در صورت نیاز)<input name="scope_id" type="number" min="1" placeholder="شناسه محدوده"></label>
                    <label class="ac-field full">توضیحات<textarea name="description" maxlength="2000" placeholder="مسئولیت‌ها و حدود این نقش"></textarea></label>
                </div>
                <button class="ac-button" type="submit">ایجاد نقش</button>
            </form>
        </section>

        <section class="ac-card">
            <h2>راهنمای محدوده دسترسی</h2>
            <div class="ac-role"><strong>سراسری</strong><p class="ac-muted">منابع کل سامانه؛ فقط برای نقش‌های بسیار محدود و مورد اعتماد.</p></div>
            <div class="ac-role"><strong>سازمانی</strong><p class="ac-muted">منابع همان سازمان؛ شناسه سازمان باید با عضویت کاربر مطابقت داشته باشد.</p></div>
            <div class="ac-role"><strong>تیمی</strong><p class="ac-muted">منابع تیم‌هایی که کاربر عضو یا سرپرست آن‌هاست.</p></div>
            <div class="ac-role"><strong>شخصی</strong><p class="ac-muted">منابع متعلق به خود کاربر؛ مناسب کارمند و نقش‌های اجرایی.</p></div>
        </section>
    </div>

    <section class="ac-card" style="margin-top:16px">
        <h2>نقش‌های سفارشی و مجوزهای آن‌ها</h2>
        @forelse($roles as $role)
            @php
                $assignedPermissionIds = $rolePermissions->where('role_id', $role->id)->pluck('permission_id')->all();
            @endphp
            <article class="ac-role">
                <div class="ac-role-head">
                    <div><strong>{{ $role->name }}</strong><div class="ac-muted" dir="ltr">{{ $role->key }}</div></div>
                    <span class="ac-tag">{{ ['global'=>'سراسری','organization'=>'سازمانی','team'=>'تیمی','own'=>'شخصی'][$role->scope_type] ?? $role->scope_type }} · {{ $role->is_active ? 'فعال' : 'غیرفعال' }}</span>
                </div>
                @if($role->is_system)
                    <p class="ac-muted">نقش سیستمی است و از این مرکز قابل تغییر نیست.</p>
                @else
                    <form method="post" action="{{ route('admin.access-center.roles.update', $role->id) }}">
                        @csrf @method('put')
                        <div class="ac-form-grid">
                            <label class="ac-field">نام<input name="name" required maxlength="160" value="{{ $role->name }}"></label>
                            <label class="ac-field">محدوده
                                <select name="scope_type">
                                    @foreach(['global'=>'سراسری','organization'=>'سازمانی','team'=>'تیمی','own'=>'شخصی'] as $value=>$label)
                                        <option value="{{ $value }}" @selected($role->scope_type===$value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="ac-field">شناسه محدوده<input name="scope_id" type="number" min="1" value="{{ $role->scope_id }}"></label>
                            <label class="ac-field">وضعیت<select name="is_active"><option value="1" @selected($role->is_active)>فعال</option><option value="0" @selected(!$role->is_active)>غیرفعال</option></select></label>
                            <label class="ac-field full">توضیحات<textarea name="description" maxlength="2000">{{ $role->description }}</textarea></label>
                        </div>
                        <button class="ac-button secondary" type="submit">ذخیره مشخصات نقش</button>
                    </form>
                    <form method="post" action="{{ route('admin.access-center.roles.permissions', $role->id) }}">
                        @csrf
                        <label class="ac-field" style="max-width:260px;margin-top:12px">محدوده پیش‌فرض مجوزها
                            <select name="scope_type">
                                @foreach(['global'=>'سراسری','organization'=>'سازمانی','team'=>'تیمی','own'=>'شخصی'] as $value=>$label)
                                    <option value="{{ $value }}" @selected($role->scope_type===$value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="ac-permissions">
                            @foreach($permissions as $group=>$groupPermissions)
                                @foreach($groupPermissions as $permission)
                                    <label class="ac-perm">
                                        <input type="checkbox" name="permissions[]" value="{{ $permission->key }}" @checked(in_array($permission->id,$assignedPermissionIds,true))>
                                        <span>{{ $permission->label }}<small>{{ $permission->key }}</small></span>
                                    </label>
                                @endforeach
                            @endforeach
                        </div>
                        <button class="ac-button" type="submit">ذخیره مجوزهای نقش</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="ac-muted">هنوز نقش سفارشی ایجاد نشده است.</p>
        @endforelse
    </section>

    <section class="ac-card" style="margin-top:16px">
        <h2>انتساب نقش و استثنای دسترسی کاربران</h2>
        <p class="ac-muted">جستجوی کاربر برای جلوگیری از نمایش فهرست حجیم. انتساب‌ها می‌توانند زمان شروع و پایان داشته باشند.</p>
        <form class="ac-search" method="get" action="{{ route('admin.access-center.index') }}">
            <input name="q" value="{{ $search }}" placeholder="نام، شماره همراه یا ایمیل کاربر">
            <button class="ac-button secondary" type="submit">جستجو</button>
        </form>
        @forelse($users as $user)
            <article class="ac-user">
                <div class="ac-user-head">
                    <strong>{{ $user->name ?: 'کاربر بدون نام' }} <small>#{{ $user->id }}</small></strong>
                    <small>{{ $user->mobile ?: $user->email ?: 'بدون راه ارتباطی' }} · {{ $user->role }}</small>
                </div>
                @if(!$user->isAdmin())
                    <form class="ac-inline" method="post" action="{{ route('admin.access-center.users.roles.store', $user->id) }}">
                        @csrf
                        <label class="ac-field">نقش سفارشی<select name="role_id" required><option value="">انتخاب نقش</option>@foreach($roles->where('is_active',true)->where('is_system',false) as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
                        <label class="ac-field">محدوده<select name="scope_type"><option value="own">شخصی</option><option value="team">تیمی</option><option value="organization">سازمانی</option><option value="global">سراسری</option></select></label>
                        <label class="ac-field">شناسه محدوده<input type="number" min="1" name="scope_id" placeholder="در صورت نیاز"></label>
                        <label class="ac-field">شروع اعتبار<input type="datetime-local" name="starts_at"></label>
                        <label class="ac-field">پایان اعتبار<input type="datetime-local" name="expires_at"></label>
                        <button class="ac-button" type="submit">انتساب نقش</button>
                    </form>
                    <form class="ac-inline" method="post" action="{{ route('admin.access-center.users.overrides.store', $user->id) }}" style="margin-top:10px">
                        @csrf
                        <label class="ac-field">مجوز مستقیم<select name="permission" required><option value="">انتخاب مجوز</option>@foreach($permissions as $group=>$groupPermissions)<optgroup label="{{ $group }}">@foreach($groupPermissions as $permission)<option value="{{ $permission->key }}">{{ $permission->label }} ({{ $permission->key }})</option>@endforeach</optgroup>@endforeach</select></label>
                        <label class="ac-field">اثر<select name="effect"><option value="deny">منع صریح</option><option value="allow">اعطای مستقیم</option></select></label>
                        <label class="ac-field">محدوده<select name="scope_type"><option value="own">شخصی</option><option value="team">تیمی</option><option value="organization">سازمانی</option><option value="global">سراسری</option></select></label>
                        <label class="ac-field">شناسه محدوده<input type="number" min="1" name="scope_id" placeholder="در صورت نیاز"></label>
                        <label class="ac-field">دلیل<input name="reason" required maxlength="1000" placeholder="دلیل این استثنا"></label>
                        <label class="ac-field">شروع اعتبار<input type="datetime-local" name="starts_at"></label>
                        <label class="ac-field">پایان اعتبار<input type="datetime-local" name="expires_at"></label>
                        <button class="ac-button secondary" type="submit">ثبت استثنا</button>
                    </form>
                @else
                    <p class="ac-muted">مدیر اصلی از مسیر انتساب نقش و مجوز مستقیم مستثنا است.</p>
                @endif
            </article>
        @empty
            <p class="ac-muted">کاربری با این عبارت پیدا نشد.</p>
        @endforelse
    </section>

    <div class="ac-grid">
        <section class="ac-card">
            <h2>نقش‌های اختصاص‌یافته</h2>
            <div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>کاربر</th><th>نقش</th><th>محدوده</th><th>اعتبار تا</th><th>عملیات</th></tr></thead><tbody>
                @forelse($assignments as $assignment)
                    <tr><td>#{{ $assignment->user_id }}</td><td>{{ $assignment->role_name }}</td><td>{{ $assignment->scope_type }}{{ $assignment->scope_id ? ' #'.$assignment->scope_id : '' }}</td><td>{{ $assignment->expires_at ?: 'بدون انقضا' }}</td><td><form method="post" action="{{ route('admin.access-center.users.roles.destroy', [$assignment->user_id,$assignment->id]) }}">@csrf @method('delete')<button class="ac-button danger" type="submit">لغو</button></form></td></tr>
                @empty<tr><td colspan="5">موردی ثبت نشده است.</td></tr>@endforelse
            </tbody></table></div>
        </section>
        <section class="ac-card">
            <h2>استثناهای مستقیم</h2>
            <div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>کاربر</th><th>مجوز</th><th>اثر</th><th>محدوده</th><th>عملیات</th></tr></thead><tbody>
                @forelse($overrides as $override)
                    <tr><td>#{{ $override->user_id }}</td><td>{{ $override->permission_label }}</td><td>{{ $override->effect==='deny'?'منع':'اعطا' }}</td><td>{{ $override->scope_type }}</td><td><form method="post" action="{{ route('admin.access-center.users.overrides.destroy', [$override->user_id,$override->id]) }}">@csrf @method('delete')<button class="ac-button danger" type="submit">لغو</button></form></td></tr>
                @empty<tr><td colspan="5">موردی ثبت نشده است.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>

    <section class="ac-card" style="margin-top:16px">
        <h2>تاریخچه امنیت و دسترسی</h2>
        <div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>زمان</th><th>عامل</th><th>کاربر هدف</th><th>رویداد</th><th>نقش</th></tr></thead><tbody>
            @forelse($auditLogs as $log)
                <tr><td>{{ $log->created_at }}</td><td>{{ $log->actor_id ?: 'سیستم' }}</td><td>{{ $log->subject_user_id ?: '—' }}</td><td dir="ltr">{{ $log->action }}</td><td>{{ $log->role_id ?: '—' }}</td></tr>
            @empty<tr><td colspan="5">هنوز رویدادی ثبت نشده است.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
