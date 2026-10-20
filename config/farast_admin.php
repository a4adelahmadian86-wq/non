<?php

/*
|--------------------------------------------------------------------------
| پیکربندی کنسول مدیریت فراست
|--------------------------------------------------------------------------
| معماری «داشبورد واحد + دامنه (Scope)»: یک داشبورد اصلی جامع وجود دارد و
| داشبورد سایر نقش‌ها، همان داشبورد با دامنه و مجوزهای محدودتر است.
*/

return [

    // کلید SiteSetting که در آن دامنهٔ سراسی کنسول ذخیره می‌شود.
    // مقادیر مجاز: global | organization | team | own
    // مقدار خالی یعنی «بدون override؛ دامنه از نقش کاربر مشتق شود».
    'scope_override_key' => env('FARAST_ADMIN_SCOPE_KEY', ''),

    // آیا مسیرهای /admin برای نقش‌های غیر ادمین (با رعایت RBAC و Scope) باز باشد؟
    // اگر false باشد، رفتار قدیمی (فقط admin) حفظ می‌شود.
    'multi_role_console' => filter_var(env('FARAST_ADMIN_MULTI_ROLE', true), FILTER_VALIDATE_BOOLEAN),

    // بخش‌های کنسول و حداقل مجوز لازم برای هر بخش.
    // این جدول هم در فیلتر ناوبری و هم در کنترل دسترسی سطح‌عمقی استفاده می‌شود.
    'sections' => [
        'overview'      => ['permission' => 'dashboard.view',     'min_scope' => 'own'],
        'users'         => ['permission' => 'users.view',         'min_scope' => 'team'],
        'documents'     => ['permission' => 'documents.view',     'min_scope' => 'own'],
        'ai'            => ['permission' => 'ai.view',            'min_scope' => 'organization'],
        'voice'         => ['permission' => 'voice.manage',       'min_scope' => 'global'],
        'finance'       => ['permission' => 'finance.view',       'min_scope' => 'organization'],
        'support'       => ['permission' => 'support.manage',     'min_scope' => 'team'],
        'announcements' => ['permission' => 'settings.manage',    'min_scope' => 'global'],
        'integrations'  => ['permission' => 'integrations.manage','min_scope' => 'global'],
        'access'        => ['permission' => 'access.manage',      'min_scope' => 'global'],
        'organizations' => ['permission' => 'organization.manage','min_scope' => 'organization'],
        'analytics'     => ['permission' => 'reports.view',       'min_scope' => 'team'],
        'audit'         => ['permission' => 'audit.view',         'min_scope' => 'global'],
        'emails'        => ['permission' => 'settings.manage',    'min_scope' => 'global'],
        'social'        => ['permission' => 'settings.manage',    'min_scope' => 'global'],
        'plans'         => ['permission' => 'billing.manage',     'min_scope' => 'global'],
        'cms'           => ['permission' => 'settings.manage',    'min_scope' => 'global'],
    ],

    // ترتیب سلسله‌مراتب دامنه (هرچه عدد بزرگ‌تر، محدودتر).
    'scope_rank' => [
        'global' => 0,
        'organization' => 1,
        'team' => 2,
        'own' => 3,
    ],
];
