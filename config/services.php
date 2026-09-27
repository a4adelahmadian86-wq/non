<?php

return [
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    'google_speech' => [
        'credentials_file' => env('GOOGLE_SPEECH_CREDENTIALS_FILE', env('GOOGLE_APPLICATION_CREDENTIALS')),
        'project_id' => env('GOOGLE_CLOUD_PROJECT'),
        'enabled' => filter_var(env('GOOGLE_SPEECH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'kavenegar' => [
        'key' => env('KAVENEGAR_API_KEY'),
        'sender' => env('KAVENEGAR_SENDER'),
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
