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
            'messenger',
            'editor-documents',
            'generated-documents',
        ],
    ],
    'health' => [
        'stale_after_minutes' => 10,
        'probe_timeout_seconds' => 8,
    ],
    'replication' => [
        'critical_copies' => 2,
        'non_critical_copies' => 1,
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