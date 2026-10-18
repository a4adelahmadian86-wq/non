<?php

return [
    'navigation' => [
        [
            'key' => 'workspace',
            'label' => 'میز کار',
            'icon' => 'fa-table-cells-large',
            'items' => [
                ['key' => 'workspace.overview', 'label' => 'نمای کلی', 'icon' => 'fa-grid-2', 'route' => 'dashboard'],
                ['key' => 'workspace.documents', 'label' => 'اسناد و فایل‌ها', 'icon' => 'fa-folder-open', 'route' => 'platform.documents'],
                ['key' => 'workspace.notifications', 'label' => 'اعلان‌های سیستم', 'icon' => 'fa-bell', 'route' => 'platform.notifications'],
                ['key' => 'workspace.workflows', 'label' => 'گردش‌کارها', 'icon' => 'fa-diagram-project', 'route' => 'platform.workflows'],
                ['key' => 'workspace.security', 'label' => 'امنیت حساب', 'icon' => 'fa-shield-halved', 'route' => 'platform.security'],
                ['key' => 'workspace.status', 'label' => 'وضعیت سیستم', 'icon' => 'fa-heart-pulse', 'route' => 'admin.index', 'fragment' => '#operations', 'admin_only' => true],
            ],
        ],
        [
            'key' => 'support_desk',
            'label' => 'پشتیبانی',
            'icon' => 'fa-headset',
            'items' => [
                ['key' => 'support.my', 'label' => 'گفتگوهای من', 'icon' => 'fa-comments', 'route' => 'support', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'support.new', 'label' => 'تیکت جدید', 'icon' => 'fa-plus', 'route' => 'support.create.form', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'support.manage', 'label' => 'میز پشتیبانی (تیکت‌ها)', 'icon' => 'fa-inbox', 'route' => 'admin.support.management', 'admin_only' => true],
            ],
        ],
        [
            'key' => 'documents',
            'label' => 'اسناد و فایل‌ها',
            'icon' => 'fa-folder-open',
            'items' => [
                ['key' => 'documents.mine', 'label' => 'اسناد من', 'icon' => 'fa-file-lines', 'route' => 'platform.documents', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.library', 'label' => 'کتابخانه خریدها', 'icon' => 'fa-book', 'route' => 'library', 'permission' => 'documents.view', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'editor',
            'label' => 'ویرایشگر',
            'icon' => 'fa-pen-ruler',
            'items' => [
                ['key' => 'editor.start', 'label' => 'ویرایشگر', 'icon' => 'fa-pen-to-square', 'route' => 'editor', 'permission' => 'editor.use', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'commerce',
            'label' => 'فروشگاه و مالی',
            'icon' => 'fa-store',
            'items' => [
                ['key' => 'commerce.wallet', 'label' => 'کیف پول', 'icon' => 'fa-wallet', 'route' => 'wallet'],
                ['key' => 'commerce.billing', 'label' => 'اشتراک و پلن', 'icon' => 'fa-credit-card', 'route' => 'platform.billing'],
                ['key' => 'commerce.wishlist', 'label' => 'علاقه‌مندی‌ها', 'icon' => 'fa-heart', 'route' => 'wishlist'],
            ],
        ],
        [
            'key' => 'notifications',
            'label' => 'اعلان‌ها و پیام‌ها',
            'icon' => 'fa-bell',
            'items' => [
                ['key' => 'notifications.alerts', 'label' => 'اعلان‌های عمومی', 'icon' => 'fa-bullhorn', 'route' => 'announcements'],
                ['key' => 'notifications.system', 'label' => 'اعلان‌های پنل', 'icon' => 'fa-bell', 'route' => 'platform.notifications'],
            ],
        ],
        [
            'key' => 'settings',
            'label' => 'تنظیمات',
            'icon' => 'fa-gear',
            'items' => [
                ['key' => 'settings.account', 'label' => 'تنظیمات حساب', 'icon' => 'fa-user-gear', 'planned' => true],
                ['key' => 'settings.finance', 'label' => 'مالی (ادمین)', 'icon' => 'fa-wallet', 'route' => 'admin.finance', 'admin_only' => true],
                ['key' => 'settings.email', 'label' => 'ایمیل (ادمین)', 'icon' => 'fa-envelope', 'route' => 'admin.emails', 'admin_only' => true],
                ['key' => 'settings.integrations', 'label' => 'یکپارچه‌سازی', 'icon' => 'fa-plug', 'route' => 'admin.integrations', 'admin_only' => true],
                ['key' => 'settings.analytics', 'label' => 'تحلیل', 'icon' => 'fa-chart-line', 'route' => 'admin.analytics', 'admin_only' => true],
            ],
        ],
    ],
];
