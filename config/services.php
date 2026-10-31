<?php

return [
    // Optional central multi-provider gateway. Empty by default preserves direct provider behavior.
    'ai_gateway' => [
        'url' => env('FARAST_AI_GATEWAY_URL', ''),
        'token' => env('FARAST_AI_GATEWAY_TOKEN', ''),
        'health_url' => env('FARAST_AI_GATEWAY_HEALTH_URL', filter_var(env('FARAST_VOICE_LOCAL_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN) ? 'http://127.0.0.1:7008/ready' : ''),
        'model' => env('FARAST_AI_GATEWAY_MODEL', 'farast-general'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    // Optional private/self-hosted speech services. No third-party cloud API is assumed.
    'voice' => [
        'local_only' => filter_var(env('FARAST_VOICE_LOCAL_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'remote_batch_enabled' => filter_var(env('FARAST_VOICE_REMOTE_BATCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'voice_asr' => [
        'url' => env('FARAST_VOICE_ASR_URL', filter_var(env('FARAST_VOICE_LOCAL_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN) ? '' : 'https://voice-typing-r5r7.onrender.com/v1/transcribe'),
        'token' => env('FARAST_VOICE_ASR_TOKEN', env('FARAST_VOICE_TOKEN')),
    ],
    'voice_tts' => [
        'url' => env('FARAST_VOICE_TTS_URL', filter_var(env('FARAST_VOICE_LOCAL_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN) ? 'http://127.0.0.1:7007/v1/synthesize' : 'https://voice-typing-r5r7.onrender.com/v1/synthesize'),
        'token' => env('FARAST_VOICE_TTS_TOKEN', env('FARAST_VOICE_TOKEN')),
        // Keep this identifier pinned to one single-speaker model on the TTS host.
        'voice_id' => env('FARAST_VOICE_TTS_VOICE_ID', 'farast-fa-male-v1'),
    ],

    'voice_stream' => [
        'url' => env('VOICE_STREAM_URL', ''),
        'gateway_secret' => env('VOICE_GATEWAY_SECRET'),
        'health_url' => env('VOICE_STREAM_HEALTH_URL', filter_var(env('FARAST_VOICE_LOCAL_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN) ? 'http://127.0.0.1:6002/health' : ''),
    ],

    'google_speech' => [
        'credentials_file' => env('GOOGLE_SPEECH_CREDENTIALS_FILE', env('GOOGLE_APPLICATION_CREDENTIALS')),
        'project_id' => env('GOOGLE_CLOUD_PROJECT'),
        'enabled' => filter_var(env('GOOGLE_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'kavenegar' => [
        'key' => env('KAVENEGAR_API_KEY'),
        'sender' => env('KAVENEGAR_SENDER'),
        // نام قالب OTP در پنل کاوه‌نگار (VerifyLookup)
        'otp_template' => env('KAVENEGAR_OTP_TEMPLATE'),
        // قالب‌های اختیاری رویدادهای پشتیبانی — یا از config/support.php
        'templates' => [
            'ticket_reply' => env('KAVENEGAR_TPL_TICKET_REPLY'),
            'ticket_closed' => env('KAVENEGAR_TPL_TICKET_CLOSED'),
            'ticket_assigned' => env('KAVENEGAR_TPL_TICKET_ASSIGNED'),
            'ticket_created_staff' => env('KAVENEGAR_TPL_TICKET_CREATED_STAFF'),
            'ticket_customer_reply' => env('KAVENEGAR_TPL_TICKET_CUSTOMER_REPLY'),
            'ticket_sla_warning' => env('KAVENEGAR_TPL_SLA_WARNING'),
            'ticket_sla_breach' => env('KAVENEGAR_TPL_SLA_BREACH'),
        ],
    ],

    // سرویس‌های ایمیل رایگان / کم‌هزینه
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => env('MAILGUN_SCHEME', 'https'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],
];
