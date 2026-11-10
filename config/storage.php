<?php

return [
    'routing' => [
        // Temporary editor input remains on the local quarantine disk. Final editor assets,
        // user file uploads and support attachments are routed to foreign storage.
        'non_critical_prefixes' => [
            'user-files',
            'ticket-attachments',
            'support-attachments',
            'editor-assets',
            'community-attachments',
            'messenger-attachments',
        ],
    ],
    'health' => [
        'stale_after_minutes' => 10,
        'probe_timeout_seconds' => 8,
    ],
    'fallback' => [
        'enabled' => true,
    ],
    'backup' => [
        'weekly_enabled' => true,
        'retention_count' => 8,
        'include_database' => true,
        'include_managed_files' => true,
    ],
];