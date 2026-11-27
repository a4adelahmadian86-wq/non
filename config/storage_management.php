<?php

return [
    'backup' => [
        // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        // Keep this secret outside the database and outside the backup destination.
        'encryption_key' => env('FARAST_BACKUP_ENCRYPTION_KEY'),
        'enable_iran_replica' => filter_var(env('FARAST_ENABLE_IRAN_BACKUP', false), FILTER_VALIDATE_BOOLEAN),
        'weekly_retention' => max(1, min(12, (int) env('FARAST_BACKUP_WEEKLY_RETENTION', 2))),
    ],
    'health' => [
        'connect_timeout' => (int) env('FARAST_STORAGE_CONNECT_TIMEOUT', 8),
        'request_timeout' => (int) env('FARAST_STORAGE_REQUEST_TIMEOUT', 300),
    ],
];