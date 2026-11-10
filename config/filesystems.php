<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => false],
        // Quarantine/staging disk: local-only for upload inspection and temporary processing.
        'private' => ['driver' => 'local', 'root' => storage_path('app/private'), 'throw' => true],
        // Application modules use one logical disk. The central router chooses the physical host.
        'managed' => ['driver' => 'farast', 'throw' => true, 'visibility' => 'private'],
        'public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false],
        'farast_remote' => [
            'driver' => 'local',
            'root' => env('FARAST_REMOTE_ROOT', storage_path('app/remote-archive')),
            'throw' => false,
        ],
    ],
    'links' => [public_path('storage') => storage_path('app/public')],
];