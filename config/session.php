<?php

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    // Account sessions remain valid until explicit logout or three years
    // without a site visit. The account-security middleware enforces the same
    // inactivity boundary against its own last_activity timestamp.
    'lifetime' => 1576800,
    'expire_on_close' => false,
    'encrypt' => true,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => 'sessions',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'farast_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_SCHEME) === 'https'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
