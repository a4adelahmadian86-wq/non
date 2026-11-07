@php
    $adminConsole = $console ?? app(\App\Services\AdminConsoleService::class);
    $adminScope = $scope ?? \App\Services\AdminConsoleService::scopeOf(auth()->user());
@endphp
<aside class="farast-admin-nav" aria-label="ناوبری مرکز عملیات">
    <div class="farast-admin-brand">
        <span class="farast-admin-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></span>
        <div><strong>فراست</strong><small>مرکز عملیات یکپارچه</small></div>
    </div>
    <div class="farast-admin-nav-label">کنسول مدیریت · دامنه: {{ $adminScope }}</div>
    <nav>
        <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) aria-current="page" @endif><i class="fa-solid fa-grid-2"></i><span>نمای کلی عملیات</span></a>
        @if($adminConsole->sectionAllowed(auth()->user(), 'users'))
            <a href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users')) aria-current="page" @endif><i class="fa-solid fa-users"></i><span>کاربران</span></a>
            <a href="{{ route('admin.crm.projects.index') }}" @if(request()->routeIs('admin.crm.projects.*')) aria-current="page" @endif><i class="fa-solid fa-diagram-project"></i><span>پروژه‌ها و پرونده مشتریان</span></a>
            <a href="{{ route('admin.crm.customers.index') }}" @if(request()->routeIs('admin.crm.customers.*')) aria-current="page" @endif><i class="fa-solid fa-address-card"></i><span>مدیریت مشتریان</span></a>
            <a href="{{ route('admin.crm.followups.index') }}" @if(request()->routeIs('admin.crm.followups.*')) aria-current="page" @endif><i class="fa-solid fa-calendar-check"></i><span>پیگیری‌ها</span></a>
        @endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'store'))
            <a href="{{ route('admin.store.index') }}" @if(request()->routeIs('admin.store.*')) aria-current="page" @endif><i class="fa-solid fa-boxes-stacked"></i><span>محصولات و دوره‌ها</span></a>
            <a class="farast-admin-nav-create-product" href="{{ route('admin.store.create') }}"><i class="fa-solid fa-circle-plus"></i><span>افزودن محصول جدید</span><b>+</b></a>
        @endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'finance'))<a href="{{ route('admin.finance') }}" @if(request()->routeIs('admin.finance')) aria-current="page" @endif><i class="fa-solid fa-wallet"></i><span>مالی و پرداخت‌ها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'integrations'))<a href="{{ route('admin.integrations') }}" @if(request()->routeIs('admin.integrations')) aria-current="page" @endif><i class="fa-solid fa-plug"></i><span>یکپارچه‌سازی سرویس‌ها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'voice'))<a href="{{ route('admin.index') }}#voice-settings"><i class="fa-solid fa-microphone-lines"></i><span>سرویس‌های صوتی</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'ai_providers'))<a href="{{ route('admin.index') }}#groq-settings"><i class="fa-solid fa-brain"></i><span>ارائه‌دهندگان AI</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'analytics'))<a href="{{ route('admin.analytics') }}" @if(request()->routeIs('admin.analytics')) aria-current="page" @endif><i class="fa-solid fa-chart-line"></i><span>تحلیل و گزارش</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'audit'))<a href="{{ route('admin.audit') }}" @if(request()->routeIs('admin.audit')) aria-current="page" @endif><i class="fa-solid fa-shield-halved"></i><span>ممیزی و امنیت</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'access'))<a href="{{ route('admin.access') }}" @if(request()->routeIs('admin.access')) aria-current="page" @endif><i class="fa-solid fa-user-shield"></i><span>نقش‌ها و مجوزها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'organizations'))<a href="{{ route('admin.organizations') }}" @if(request()->routeIs('admin.organizations')) aria-current="page" @endif><i class="fa-solid fa-building"></i><span>سازمان‌ها و تیم‌ها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'announcements'))<a href="{{ route('admin.announcements.index') }}" @if(request()->routeIs('admin.announcements.*')) aria-current="page" @endif><i class="fa-solid fa-bullhorn"></i><span>محتوا و اعلان‌ها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'support_admin'))<a href="{{ route('admin.support.management') }}" @if(request()->routeIs('admin.support.management')) aria-current="page" @endif><i class="fa-solid fa-headset"></i><span>مرکز پشتیبانی</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'plans'))<a href="{{ route('admin.plans') }}" @if(request()->routeIs('admin.plans')) aria-current="page" @endif><i class="fa-solid fa-layer-group"></i><span>پلن‌ها و اشتراک‌ها</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'cms'))<a href="{{ route('admin.cms') }}" @if(request()->routeIs('admin.cms')) aria-current="page" @endif><i class="fa-solid fa-window-maximize"></i><span>مدیریت محتوای سایت</span></a>@endif
    </nav>
    <div class="farast-admin-nav-bottom">
        @if($adminConsole->sectionAllowed(auth()->user(), 'emails'))<a href="{{ route('admin.emails') }}"><i class="fa-solid fa-envelope"></i><span>ایمیل</span></a>@endif
        @if($adminConsole->sectionAllowed(auth()->user(), 'social'))<a href="{{ route('admin.social') }}"><i class="fa-solid fa-share-nodes"></i><span>شبکه‌های اجتماعی</span></a>@endif
        <a href="{{ route('home') }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>مشاهده سایت</span></a>
    </div>
    <div class="farast-admin-live {{ isset($systemHealth) && ($systemHealth['db_ok'] ?? false) && ($systemHealth['cache_ok'] ?? false) && ($systemHealth['storage_writable'] ?? false) && ($systemHealth['private_storage_ok'] ?? false) ? 'is-healthy' : 'is-scoped' }}">
        <span></span><div><b>مرکز کنترل فراست</b><small>{{ isset($systemHealth) ? 'وضعیت زیرساخت در داشبورد اصلی' : 'دسترسی بر پایه نقش و دامنه' }}</small></div>
    </div>
</aside>
