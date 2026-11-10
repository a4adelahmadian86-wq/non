@php
    $adminConsole = $console ?? app(\App\Services\AdminConsoleService::class);
    $adminScope = $scope ?? \App\Services\AdminConsoleService::scopeOf(auth()->user());
    $catalogActive = request()->routeIs('admin.store.*', 'admin.discussions.*', 'admin.discussion-reports.*');
    $customersActive = request()->routeIs('admin.users', 'admin.crm.*');
    $financeActive = request()->routeIs('admin.finance', 'admin.plans');
    $configurationActive = request()->routeIs('admin.integrations');
    $governanceActive = request()->routeIs('admin.analytics', 'admin.audit', 'admin.access', 'admin.organizations');
    $communicationActive = request()->routeIs('admin.announcements.*', 'admin.support.*', 'admin.cms', 'admin.emails', 'admin.social');
@endphp
<aside class="farast-admin-nav" aria-label="ناوبری مرکز عملیات">
    <div class="farast-admin-brand">
        <span class="farast-admin-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></span>
        <div><strong>فراست</strong><small>مرکز عملیات یکپارچه</small></div>
    </div>
    <div class="farast-admin-nav-label">کنسول مدیریت · دامنه: {{ $adminScope }}</div>

    <nav aria-label="دسته‌بندی‌های مدیریت">
        <details class="farast-admin-nav-group" open>
            <summary><span><i class="fa-solid fa-gauge-high"></i><b>عملیات</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
            <div class="farast-admin-nav-group__items">
                <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-grid-2"></i><span>نمای کلی عملیات</span></a>
            </div>
        </details>

        @if($adminConsole->sectionAllowed(auth()->user(), 'store') || $adminConsole->sectionAllowed(auth()->user(), 'users'))
            <details class="farast-admin-nav-group" @if($catalogActive || $customersActive) open @endif>
                <summary><span><i class="fa-solid fa-boxes-stacked"></i><b>محصولات و مشتریان</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
                <div class="farast-admin-nav-group__items">
                    @if($adminConsole->sectionAllowed(auth()->user(), 'store'))
                        <a href="{{ route('admin.store.index') }}" @if(request()->routeIs('admin.store.index', 'admin.store.edit')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-boxes-stacked"></i><span>محصولات و دوره‌ها</span></a>
                        <a href="{{ route('admin.store.create') }}" @if(request()->routeIs('admin.store.create')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-circle-plus"></i><span>افزودن محصول</span></a>
                        <a href="{{ route('admin.store.operations') }}" @if(request()->routeIs('admin.store.operations*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-gears"></i><span>عملیات و پردازش محصولات</span></a>
                        <a href="{{ route('admin.discussions.index') }}" @if(request()->routeIs('admin.discussions.*')) class="is-active" aria-current="page" @endif><i class="fa-regular fa-comments"></i><span>نظرات و پرسش‌ها</span></a>
                        <a href="{{ route('admin.discussion-reports.index') }}" @if(request()->routeIs('admin.discussion-reports.*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-flag"></i><span>گزارش‌های جامعه</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'users'))
                        <a href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-users"></i><span>کاربران</span></a>
                        <a href="{{ route('admin.crm.projects.index') }}" @if(request()->routeIs('admin.crm.projects.*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-diagram-project"></i><span>پرونده‌ها و پروژه‌های مشتریان</span></a>
                        <a href="{{ route('admin.crm.customers.index') }}" @if(request()->routeIs('admin.crm.customers.*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-address-card"></i><span>مدیریت مشتریان</span></a>
                        <a href="{{ route('admin.crm.followups.index') }}" @if(request()->routeIs('admin.crm.followups.*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-calendar-check"></i><span>پیگیری‌های مشتریان</span></a>
                    @endif
                </div>
            </details>
        @endif

        @if($adminConsole->sectionAllowed(auth()->user(), 'finance') || $adminConsole->sectionAllowed(auth()->user(), 'plans'))
            <details class="farast-admin-nav-group" @if($financeActive) open @endif>
                <summary><span><i class="fa-solid fa-wallet"></i><b>مالی و اشتراک</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
                <div class="farast-admin-nav-group__items">
                    @if($adminConsole->sectionAllowed(auth()->user(), 'finance'))
                        <a href="{{ route('admin.finance') }}" @if(request()->routeIs('admin.finance')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-wallet"></i><span>مالی و پرداخت‌ها</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'plans'))
                        <a href="{{ route('admin.plans') }}" @if(request()->routeIs('admin.plans')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-layer-group"></i><span>طرح‌ها و اشتراک‌ها</span></a>
                    @endif
                </div>
            </details>
        @endif

        @if($adminConsole->sectionAllowed(auth()->user(), 'integrations') || $adminConsole->sectionAllowed(auth()->user(), 'settings') || $adminConsole->sectionAllowed(auth()->user(), 'pricing') || $adminConsole->sectionAllowed(auth()->user(), 'voice') || $adminConsole->sectionAllowed(auth()->user(), 'ai_providers'))
            <details class="farast-admin-nav-group" @if($configurationActive) open @endif>
                <summary><span><i class="fa-solid fa-sliders"></i><b>پیکربندی پلتفرم</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
                <div class="farast-admin-nav-group__items">
                    @if($adminConsole->sectionAllowed(auth()->user(), 'integrations'))
                        <a href="{{ route('admin.integrations') }}" @if(request()->routeIs('admin.integrations')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-plug"></i><span>یکپارچه‌سازی سرویس‌ها</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'settings'))
                        <a href="{{ route('admin.index') }}#ai-settings"><i class="fa-solid fa-sliders"></i><span>تنظیمات مرکزی AI</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'pricing'))
                        <a href="{{ route('admin.index') }}#pricing"><i class="fa-solid fa-tags"></i><span>قیمت‌گذاری خدمات</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'voice'))
                        <a href="{{ route('admin.index') }}#voice-settings"><i class="fa-solid fa-microphone-lines"></i><span>سرویس‌های صوتی</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'ai_providers'))
                        <a href="{{ route('admin.index') }}#groq-settings"><i class="fa-solid fa-brain"></i><span>ارائه‌دهندگان AI</span></a>
                    @endif
                </div>
            </details>
        @endif

        @if($adminConsole->sectionAllowed(auth()->user(), 'analytics') || $adminConsole->sectionAllowed(auth()->user(), 'audit') || $adminConsole->sectionAllowed(auth()->user(), 'access') || $adminConsole->sectionAllowed(auth()->user(), 'organizations'))
            <details class="farast-admin-nav-group" @if($governanceActive) open @endif>
                <summary><span><i class="fa-solid fa-shield-halved"></i><b>حاکمیت و تحلیل</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
                <div class="farast-admin-nav-group__items">
                    @if($adminConsole->sectionAllowed(auth()->user(), 'analytics'))
                        <a href="{{ route('admin.analytics') }}" @if(request()->routeIs('admin.analytics')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-chart-line"></i><span>تحلیل و گزارش</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'audit'))
                        <a href="{{ route('admin.audit') }}" @if(request()->routeIs('admin.audit')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-shield-halved"></i><span>ممیزی و امنیت</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'access'))
                        <a href="{{ route('admin.access') }}" @if(request()->routeIs('admin.access')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-user-shield"></i><span>نقش‌ها و مجوزها</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'organizations'))
                        <a href="{{ route('admin.organizations') }}" @if(request()->routeIs('admin.organizations')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-building"></i><span>سازمان‌ها و تیم‌ها</span></a>
                    @endif
                </div>
            </details>
        @endif

        @if($adminConsole->sectionAllowed(auth()->user(), 'announcements') || $adminConsole->sectionAllowed(auth()->user(), 'support_admin') || $adminConsole->sectionAllowed(auth()->user(), 'cms') || $adminConsole->sectionAllowed(auth()->user(), 'emails') || $adminConsole->sectionAllowed(auth()->user(), 'social'))
            <details class="farast-admin-nav-group" @if($communicationActive) open @endif>
                <summary><span><i class="fa-solid fa-comments"></i><b>ارتباطات و محتوا</b></span><i class="fa-solid fa-chevron-down group-chevron"></i></summary>
                <div class="farast-admin-nav-group__items">
                    @if($adminConsole->sectionAllowed(auth()->user(), 'announcements'))
                        <a href="{{ route('admin.announcements.index') }}" @if(request()->routeIs('admin.announcements.*')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-bullhorn"></i><span>محتوا و اعلان‌ها</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'support_admin'))
                        <a href="{{ route('admin.support.management') }}" @if(request()->routeIs('admin.support.management')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-headset"></i><span>مرکز پشتیبانی</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'cms'))
                        <a href="{{ route('admin.cms') }}" @if(request()->routeIs('admin.cms')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-window-maximize"></i><span>مدیریت محتوای سایت</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'emails'))
                        <a href="{{ route('admin.emails') }}" @if(request()->routeIs('admin.emails')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-envelope"></i><span>ایمیل</span></a>
                    @endif
                    @if($adminConsole->sectionAllowed(auth()->user(), 'social'))
                        <a href="{{ route('admin.social') }}" @if(request()->routeIs('admin.social')) class="is-active" aria-current="page" @endif><i class="fa-solid fa-share-nodes"></i><span>شبکه‌های اجتماعی</span></a>
                    @endif
                </div>
            </details>
        @endif
    </nav>

    <div class="farast-admin-nav-bottom">
        <a href="{{ route('home') }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>مشاهده سایت</span></a>
    </div>
    <div class="farast-admin-live {{ isset($systemHealth) && ($systemHealth['db_ok'] ?? false) && ($systemHealth['cache_ok'] ?? false) && ($systemHealth['storage_writable'] ?? false) && ($systemHealth['private_storage_ok'] ?? false) ? 'is-healthy' : 'is-scoped' }}">
        <span></span><div><b>مرکز کنترل فراست</b><small>{{ isset($systemHealth) ? 'وضعیت زیرساخت در داشبورد اصلی' : 'دسترسی بر پایه نقش و دامنه' }}</small></div>
    </div>
</aside>

@push('scripts')
<script>
(() => {
    const nav = document.querySelector('.farast-admin-nav nav');
    if (!nav) return;
    const groups = [...nav.querySelectorAll('.farast-admin-nav-group')];
    const mobile = window.matchMedia('(max-width: 760px)');
    const syncMode = () => {
        if (mobile.matches) groups.forEach(group => group.removeAttribute('open'));
        else groups.forEach(group => {
            if (group.querySelector('a.is-active')) group.open = true;
        });
    };
    syncMode();
    mobile.addEventListener?.('change', syncMode);
    groups.forEach(group => group.addEventListener('toggle', () => {
        if (!mobile.matches || !group.open) return;
        groups.forEach(other => { if (other !== group) other.removeAttribute('open'); });
    }));
})();
</script>
@endpush
