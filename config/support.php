<?php

return [
    /*
    |--------------------------------------------------------------------------
    | اهداف SLA (دقیقه) — زمان اولین پاسخ بر اساس اولویت
    |--------------------------------------------------------------------------
    */
    'sla_first_response_minutes' => [
        'high' => (int) env('SUPPORT_SLA_HIGH', 60),      // 1 ساعت
        'normal' => (int) env('SUPPORT_SLA_NORMAL', 240), // 4 ساعت
        'low' => (int) env('SUPPORT_SLA_LOW', 1440),      // 24 ساعت
    ],

    /*
    | زمان حل کامل (دقیقه) از لحظه ایجاد
    */
    'sla_resolution_minutes' => [
        'high' => (int) env('SUPPORT_RESOLVE_HIGH', 480),     // 8 ساعت
        'normal' => (int) env('SUPPORT_RESOLVE_NORMAL', 1440), // 24 ساعت
        'low' => (int) env('SUPPORT_RESOLVE_LOW', 4320),       // 72 ساعت
    ],

    'business_hours_only' => filter_var(env('SUPPORT_SLA_BUSINESS_HOURS', false), FILTER_VALIDATE_BOOLEAN),
    'business_hours' => [
        'start' => 9,
        'end' => 18,
        'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
        'weekend' => [5, 6], // جمعه=5، شنبه=6 در Carbon (0=یکشنبه) — در ایران پنجشنبه/جمعه بسته به سازمان
    ],
];
