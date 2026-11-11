<?php

$alertUserIds = array_values(array_filter(array_map(
    'intval',
    preg_split('/[,;]+/', (string) env('FARAST_OBSERVABILITY_ALERT_USER_IDS', '')) ?: []
), static fn (int $id): bool => $id > 0));

return [
    // Keep environment access inside configuration so config:cache is authoritative.
    'enabled' => filter_var(env('FARAST_OBSERVABILITY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'alerts' => [
        // Explicit user IDs only; no implicit broadcast to every administrator.
        'user_ids' => $alertUserIds,
        'cooldown_minutes' => max(5, min(1440, (int) env('FARAST_OBSERVABILITY_ALERT_COOLDOWN_MINUTES', 30))),
    ],
    'webhook' => [
        // Optional operator-managed out-of-band destination. Only HTTPS endpoints are accepted.
        'url' => env('FARAST_OBSERVABILITY_WEBHOOK_URL'),
        'token' => env('FARAST_OBSERVABILITY_WEBHOOK_TOKEN'),
    ],
    'security' => [
        'failed_authentication_window_minutes' => 10,
        'failed_authentication_warning_threshold' => 5,
        'failed_authentication_critical_threshold' => 10,
    ],
];
