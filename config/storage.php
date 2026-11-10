<?php

return [
    'routing' => [
        'non_critical_prefixes' => [
            'user-files',
            'ticket-attachments',
            'support-attachments',
            'editor-assets',
            'community-attachments',
            'messenger-attachments',
            'editor-documents',
            'generated-documents',
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