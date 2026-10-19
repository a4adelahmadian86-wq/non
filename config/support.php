<?php

return [
    /*
    |--------------------------------------------------------------------------
    | اهداف SLA (دقیقه کاری یا تقویمی)
    |--------------------------------------------------------------------------
    */
    'sla_first_response_minutes' => [
        'high' => (int) env('SUPPORT_SLA_HIGH', 60),
        'normal' => (int) env('SUPPORT_SLA_NORMAL', 240),
        'low' => (int) env('SUPPORT_SLA_LOW', 1440),
    ],

    'sla_resolution_minutes' => [
        'high' => (int) env('SUPPORT_RESOLVE_HIGH', 480),
        'normal' => (int) env('SUPPORT_RESOLVE_NORMAL', 1440),
        'low' => (int) env('SUPPORT_RESOLVE_LOW', 4320),
    ],

    /*
    | اگر true باشد مهلت‌ها فقط در ساعات کاری شمرده می‌شود (منطق رایج ایران).
    */
    'business_hours_only' => filter_var(env('SUPPORT_SLA_BUSINESS_HOURS', true), FILTER_VALIDATE_BOOLEAN),

    'business_hours' => [
        'start' => (int) env('SUPPORT_BH_START', 9),
        'end' => (int) env('SUPPORT_BH_END', 18),
        'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
        // Carbon: 0=یکشنبه … 4=پنجشنبه، 5=جمعه، 6=شنبه
        // پیش‌فرض: پنجشنبه و جمعه تعطیل (الگوی رایج بسیاری از سازمان‌های ایرانی)
        'weekend' => array_map('intval', explode(',', env('SUPPORT_WEEKEND', '4,5'))),
    ],

    /*
    | چند دقیقه قبل از نقض SLA هشدار بدهد
    */
    'warn_before_minutes' => (int) env('SUPPORT_SLA_WARN_BEFORE', 30),

    'monitor_enabled' => filter_var(env('SUPPORT_SLA_MONITOR', true), FILTER_VALIDATE_BOOLEAN),
];
