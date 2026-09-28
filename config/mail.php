<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => (env('MAIL_HOST') === 'mailpit' || str_contains((string) env('MAIL_HOST'), 'mailpit'))
                ? '127.0.0.1'
                : env('MAIL_HOST', '127.0.0.1'),
            'port' => (env('MAIL_HOST') === 'mailpit' || str_contains((string) env('MAIL_HOST'), 'mailpit'))
                ? 25
                : env('MAIL_PORT', 25),
            'encryption' => env('MAIL_ENCRYPTION'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'mailgun' => [
            'transport' => 'mailgun',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL', 'stack'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['log', 'smtp'],
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@localhost'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'FARAST')),
    ],
];
