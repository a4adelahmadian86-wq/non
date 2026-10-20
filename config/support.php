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

    /*
    |--------------------------------------------------------------------------
    | نام قالب‌های VerifyLookup کاوه‌نگار برای رویدادهای پشتیبانی
    | خالی = ارسال متنی ساده (اگر sms_*_enabled در تنظیمات سایت روشن باشد)
    |--------------------------------------------------------------------------
    |
    | پیشنهاد توکن‌ها در پنل کاوه‌نگار:
    |  ticket_reply:        %token = کد تیکت
    |  ticket_closed:       %token = کد تیکت
    |  ticket_assigned:     %token = کد تیکت
    |  ticket_created_staff:%token = کد تیکت ، %token2 = موضوع کوتاه
    |  ticket_sla_warning:  %token = کد تیکت ، %token2 = دقیقه باقیمانده
    |  ticket_sla_breach:   %token = کد تیکت
    */
    'sms_templates' => [
        'ticket_reply' => env('KAVENEGAR_TPL_TICKET_REPLY'),
        'ticket_closed' => env('KAVENEGAR_TPL_TICKET_CLOSED'),
        'ticket_assigned' => env('KAVENEGAR_TPL_TICKET_ASSIGNED'),
        'ticket_created_staff' => env('KAVENEGAR_TPL_TICKET_CREATED_STAFF'),
        'ticket_customer_reply' => env('KAVENEGAR_TPL_TICKET_CUSTOMER_REPLY'),
        'ticket_sla_warning' => env('KAVENEGAR_TPL_SLA_WARNING'),
        'ticket_sla_breach' => env('KAVENEGAR_TPL_SLA_BREACH'),
    ],


    /*
    | پاسخ‌های آماده میز پشتیبانی (قابل گسترش)
    */
    'canned_replies' => [
        'سلام، پیام شما دریافت شد. در حال بررسی هستیم و به‌زودی پاسخ می‌دهیم.',
        'برای پیگیری دقیق‌تر لطفاً اسکرین‌شات، زمان دقیق خطا و مرورگر/دستگاه را ارسال کنید.',
        'مشکل بررسی و برطرف شد. لطفاً مجدداً امتحان کنید و نتیجه را اعلام بفرمایید.',
        'این مورد نیاز به اطلاعات تکمیلی دارد. تا دریافت پاسخ شما، تیکت در وضعیت «در بررسی» می‌ماند.',
        'با تشکر از صبوری شما. تیکت بسته شد. در صورت نیاز می‌توانید تیکت جدید ثبت کنید.',
        'درخواست شما به بخش مالی ارجاع شد و نتیجه از همین تیکت اعلام می‌شود.',
    ],
];
