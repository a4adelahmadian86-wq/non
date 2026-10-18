<?php

return [
    'navigation' => [
        [
            'key' => 'workspace', 'label' => 'میز کار', 'icon' => 'fa-table-cells-large',
            'items' => [
                ['key' => 'workspace.overview', 'label' => 'نمای کلی', 'icon' => 'fa-grid-2', 'route' => 'dashboard'],
                ['key' => 'workspace.recent', 'label' => 'فعالیت‌های اخیر', 'icon' => 'fa-clock-rotate-left', 'planned' => true],
                ['key' => 'workspace.tasks', 'label' => 'وظایف من', 'icon' => 'fa-list-check', 'planned' => true],
                ['key' => 'workspace.action', 'label' => 'موارد نیازمند اقدام', 'icon' => 'fa-bell-exclamation', 'planned' => true],
                ['key' => 'workspace.shortcuts', 'label' => 'میانبرها', 'icon' => 'fa-bolt', 'planned' => true],
                ['key' => 'workspace.status', 'label' => 'وضعیت سیستم', 'icon' => 'fa-heart-pulse', 'route' => 'admin.index', 'fragment' => '#operations', 'admin_only' => true],
            ],
        ],
        [
            'key' => 'support_desk', 'label' => 'پشتیبانی', 'icon' => 'fa-headset',
            'items' => [
                ['key' => 'support.my', 'label' => 'گفتگوهای من', 'icon' => 'fa-comments', 'route' => 'support', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'support.new', 'label' => 'تیکت جدید', 'icon' => 'fa-plus', 'route' => 'support.create.form', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'support.manage', 'label' => 'میز پشتیبانی (تیکت‌ها)', 'icon' => 'fa-inbox', 'route' => 'admin.support.management', 'admin_only' => true],
            ],
        ],
        [
            'key' => 'documents', 'label' => 'اسناد و فایل‌ها', 'icon' => 'fa-folder-open',
            'items' => [
                ['key' => 'documents.mine', 'label' => 'اسناد من', 'icon' => 'fa-file-lines', 'route' => 'dashboard', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.files', 'label' => 'فایل‌ها', 'icon' => 'fa-folder', 'route' => 'library', 'permission' => 'documents.view', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'editor', 'label' => 'ویرایشگر', 'icon' => 'fa-pen-ruler',
            'items' => [
                ['key' => 'editor.start', 'label' => 'ویرایشگر', 'icon' => 'fa-pen-to-square', 'route' => 'editor', 'permission' => 'editor.use', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'notifications', 'label' => 'اعلان‌ها و پیام‌ها', 'icon' => 'fa-bell',
            'items' => [
                ['key' => 'notifications.alerts', 'label' => 'اعلان‌ها', 'icon' => 'fa-bell', 'route' => 'announcements'],
            ],
        ],
        [
            'key' => 'settings', 'label' => 'تنظیمات', 'icon' => 'fa-gear',
            'items' => [
                ['key' => 'settings.account', 'label' => 'تنظیمات حساب', 'icon' => 'fa-user-gear', 'planned' => true],
                ['key' => 'settings.finance', 'label' => 'مالی', 'icon' => 'fa-wallet', 'route' => 'admin.finance', 'admin_only' => true],
                ['key' => 'settings.email', 'label' => 'ایمیل', 'icon' => 'fa-envelope', 'route' => 'admin.emails', 'admin_only' => true],
            ],
        ],
    ],
];
