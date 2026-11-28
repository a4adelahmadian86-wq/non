<?php

return [
    'backup' => [
        // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        // Keep this secret outside the database and outside the backup destination.
        'encryption_key' => env('FARAST_BACKUP_ENCRYPTION_KEY'),
        'enable_iran_replica' => filter_var(env('FARAST_ENABLE_IRAN_BACKUP', false), FILTER_VALIDATE_BOOLEAN),
        // Full weekly snapshots read every managed source object; keep metered Iran primary excluded until incremental backup is implemented.
        'allow_iran_primary_full_source_reads' => filter_var(env('FARAST_BACKUP_ALLOW_IRAN_PRIMARY_FULL_READS', false), FILTER_VALIDATE_BOOLEAN),
        // Keep only the newest verified full snapshot per destination/type by default.
        'keep_latest' => max(1, min(10, (int) env('FARAST_BACKUP_KEEP_LATEST', 1))),
        'use_incremental_format' => filter_var(env('FARAST_BACKUP_INCREMENTAL', true), FILTER_VALIDATE_BOOLEAN),
        'max_iran_source_read_bytes_per_run' => max(1, (int) env('FARAST_BACKUP_IRAN_READ_PER_RUN', 536870912)),
        'max_iran_source_read_bytes_per_month' => max(536870912, (int) env('FARAST_BACKUP_IRAN_READ_PER_MONTH', 5368709120)),
        'max_target_storage_bytes' => max(1, (int) env('FARAST_BACKUP_TARGET_MAX_BYTES', 4294967296)),
        'max_class_a_ops_per_month' => max(1, (int) env('FARAST_BACKUP_MAX_CLASS_A_OPS', 8000)),
        'max_class_b_ops_per_month' => max(1, (int) env('FARAST_BACKUP_MAX_CLASS_B_OPS', 80000)),
        'max_iran_ops_per_month' => max(1, (int) env('FARAST_BACKUP_IRAN_MAX_OPS', 7000)),
    ],
    'health' => [
        'connect_timeout' => (int) env('FARAST_STORAGE_CONNECT_TIMEOUT', 8),
        'request_timeout' => (int) env('FARAST_STORAGE_REQUEST_TIMEOUT', 300),
    ],
];