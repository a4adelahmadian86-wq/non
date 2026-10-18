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
                ['key' => 'documents.all', 'label' => 'همه اسناد', 'icon' => 'fa-files', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'global', 'admin_only' => true],
                ['key' => 'documents.mine', 'label' => 'اسناد من', 'icon' => 'fa-file-lines', 'route' => 'dashboard', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.recent', 'label' => 'اسناد اخیر', 'icon' => 'fa-clock-rotate-left', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.files', 'label' => 'فایل‌ها', 'icon' => 'fa-folder', 'route' => 'library', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.folders', 'label' => 'پوشه‌ها', 'icon' => 'fa-folder-tree', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.shared', 'label' => 'اشتراک‌گذاری‌ها', 'icon' => 'fa-share-nodes', 'planned' => true, 'permission' => 'documents.share', 'scope' => 'team'],
                ['key' => 'documents.deleted', 'label' => 'موارد حذف‌شده', 'icon' => 'fa-trash-can', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'documents.archive', 'label' => 'بایگانی', 'icon' => 'fa-box-archive', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'editor', 'label' => 'ویرایشگر', 'icon' => 'fa-pen-ruler',
            'items' => [
                ['key' => 'editor.start', 'label' => 'ویرایشگر', 'icon' => 'fa-pen-to-square', 'route' => 'editor', 'permission' => 'editor.use', 'scope' => 'own'],
                ['key' => 'editor.recent', 'label' => 'اسناد اخیر', 'icon' => 'fa-clock-rotate-left', 'route' => 'dashboard', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'editor.templates', 'label' => 'قالب‌ها', 'icon' => 'fa-layer-group', 'planned' => true],
                ['key' => 'editor.drafts', 'label' => 'پیش‌نویس‌ها', 'icon' => 'fa-file-pen', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'editor.favorites', 'label' => 'موارد مورد علاقه', 'icon' => 'fa-star', 'planned' => true, 'permission' => 'documents.view', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'automation', 'label' => 'اتوماسیون و گردش کار', 'icon' => 'fa-diagram-project',
            'items' => [
                ['key' => 'automation.workflows', 'label' => 'گردش‌های کار', 'icon' => 'fa-route', 'planned' => true, 'permission' => 'workflows.view', 'scope' => 'team'],
                ['key' => 'automation.processes', 'label' => 'فرآیندها', 'icon' => 'fa-diagram-next', 'planned' => true, 'permission' => 'workflows.manage', 'scope' => 'organization'],
                ['key' => 'automation.rules', 'label' => 'قواعد خودکار', 'icon' => 'fa-gears', 'planned' => true, 'permission' => 'workflows.manage', 'scope' => 'organization'],
                ['key' => 'automation.jobs', 'label' => 'صف کارها', 'icon' => 'fa-bars-progress', 'planned' => true, 'permission' => 'workflows.view', 'scope' => 'organization'],
                ['key' => 'automation.history', 'label' => 'تاریخچه اجرا', 'icon' => 'fa-clock-rotate-left', 'planned' => true, 'permission' => 'workflows.view', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'ai', 'label' => 'هوش مصنوعی', 'icon' => 'fa-robot',
            'items' => [
                ['key' => 'ai.assistant', 'label' => 'دستیار هوشمند', 'icon' => 'fa-wand-magic-sparkles', 'planned' => true, 'permission' => 'ai.use', 'scope' => 'own'],
                ['key' => 'ai.history', 'label' => 'تاریخچه پیشنهادها', 'icon' => 'fa-clock-rotate-left', 'planned' => true, 'permission' => 'ai.use', 'scope' => 'own'],
                ['key' => 'ai.models', 'label' => 'مدل‌ها و تنظیمات', 'icon' => 'fa-sliders', 'route' => 'admin.index', 'fragment' => '#ai', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
                ['key' => 'ai.usage', 'label' => 'مصرف هوش مصنوعی', 'icon' => 'fa-chart-simple', 'planned' => true, 'permission' => 'reports.view', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'collaboration', 'label' => 'تیم و همکاری', 'icon' => 'fa-people-group',
            'items' => [
                ['key' => 'collaboration.teams', 'label' => 'تیم‌ها', 'icon' => 'fa-user-group', 'planned' => true, 'permission' => 'teams.view', 'scope' => 'organization'],
                ['key' => 'collaboration.members', 'label' => 'اعضا', 'icon' => 'fa-users', 'planned' => true, 'permission' => 'teams.view', 'scope' => 'organization'],
                ['key' => 'collaboration.roles', 'label' => 'نقش‌ها', 'icon' => 'fa-user-shield', 'route' => 'admin.access', 'admin_only' => true, 'permission' => 'roles.manage', 'scope' => 'organization'],
                ['key' => 'collaboration.invites', 'label' => 'دعوت‌ها', 'icon' => 'fa-envelope-open-text', 'planned' => true, 'permission' => 'teams.manage', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'reports', 'label' => 'گزارش‌ها و تحلیل‌ها', 'icon' => 'fa-chart-line',
            'items' => [
                ['key' => 'reports.overview', 'label' => 'نمای گزارش‌ها', 'icon' => 'fa-chart-pie', 'route' => 'admin.analytics', 'admin_only' => true, 'permission' => 'reports.view', 'scope' => 'organization'],
                ['key' => 'reports.usage', 'label' => 'مصرف منابع', 'icon' => 'fa-gauge-high', 'planned' => true, 'permission' => 'reports.view', 'scope' => 'organization'],
                ['key' => 'reports.finance', 'label' => 'گزارش مالی', 'icon' => 'fa-coins', 'route' => 'admin.finance', 'admin_only' => true, 'permission' => 'finance.view', 'scope' => 'organization'],
                ['key' => 'reports.exports', 'label' => 'خروجی‌ها', 'icon' => 'fa-file-export', 'planned' => true, 'permission' => 'reports.export', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'notifications', 'label' => 'اعلان‌ها و پیام‌ها', 'icon' => 'fa-bell',
            'items' => [
                ['key' => 'notifications.alerts', 'label' => 'اعلان‌ها', 'icon' => 'fa-bell', 'route' => 'announcements'],
                ['key' => 'notifications.messages', 'label' => 'پیام‌ها', 'icon' => 'fa-comments', 'planned' => true],
                ['key' => 'notifications.system', 'label' => 'اعلان‌های سیستم', 'icon' => 'fa-circle-info', 'planned' => true, 'admin_only' => true],
            ],
        ],
        [
            'key' => 'access', 'label' => 'مدیریت کاربران و دسترسی‌ها', 'icon' => 'fa-user-lock',
            'items' => [
                ['key' => 'access.users', 'label' => 'کاربران', 'icon' => 'fa-users', 'route' => 'admin.access', 'admin_only' => true, 'permission' => 'users.view', 'scope' => 'organization'],
                ['key' => 'access.roles', 'label' => 'نقش‌ها و مجوزها', 'icon' => 'fa-key', 'route' => 'admin.access', 'admin_only' => true, 'permission' => 'roles.manage', 'scope' => 'organization'],
                ['key' => 'access.sessions', 'label' => 'نشست‌های فعال', 'icon' => 'fa-desktop', 'planned' => true, 'admin_only' => true, 'permission' => 'security.view', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'organization', 'label' => 'مدیریت سازمان', 'icon' => 'fa-building',
            'items' => [
                ['key' => 'organization.profile', 'label' => 'پروفایل سازمان', 'icon' => 'fa-id-card', 'route' => 'admin.organizations', 'admin_only' => true, 'permission' => 'org.manage', 'scope' => 'organization'],
                ['key' => 'organization.billing', 'label' => 'صورتحساب سازمان', 'icon' => 'fa-file-invoice-dollar', 'route' => 'admin.finance', 'admin_only' => true, 'permission' => 'finance.view', 'scope' => 'organization'],
                ['key' => 'organization.plans', 'label' => 'پلن‌ها', 'icon' => 'fa-crown', 'route' => 'admin.plans', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
            ],
        ],
        [
            'key' => 'security', 'label' => 'امنیت و نظارت', 'icon' => 'fa-shield-halved',
            'items' => [
                ['key' => 'security.audit', 'label' => 'گزارش ممیزی', 'icon' => 'fa-clipboard-list', 'route' => 'admin.audit', 'admin_only' => true, 'permission' => 'security.view', 'scope' => 'organization'],
                ['key' => 'security.logs', 'label' => 'لاگ‌ها', 'icon' => 'fa-list-ul', 'planned' => true, 'admin_only' => true, 'permission' => 'security.view', 'scope' => 'organization'],
                ['key' => 'security.alerts', 'label' => 'هشدارهای امنیتی', 'icon' => 'fa-triangle-exclamation', 'planned' => true, 'admin_only' => true, 'permission' => 'security.view', 'scope' => 'organization'],
            ],
        ],
        [
            'key' => 'settings', 'label' => 'تنظیمات', 'icon' => 'fa-gear',
            'items' => [
                ['key' => 'settings.account', 'label' => 'تنظیمات حساب', 'icon' => 'fa-user-gear', 'planned' => true],
                ['key' => 'settings.preferences', 'label' => 'ترجیحات', 'icon' => 'fa-sliders', 'planned' => true],
                ['key' => 'settings.finance', 'label' => 'مالی', 'icon' => 'fa-wallet', 'route' => 'admin.finance', 'admin_only' => true, 'permission' => 'finance.view', 'scope' => 'organization'],
                ['key' => 'settings.email', 'label' => 'ایمیل', 'icon' => 'fa-envelope', 'route' => 'admin.emails', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
                ['key' => 'settings.integrations', 'label' => 'یکپارچه‌سازی‌ها', 'icon' => 'fa-plug', 'route' => 'admin.integrations', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
                ['key' => 'settings.providers', 'label' => 'ارائه‌دهندگان', 'icon' => 'fa-server', 'route' => 'admin.index', 'fragment' => '#providers', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
                ['key' => 'settings.cms', 'label' => 'مدیریت محتوا', 'icon' => 'fa-newspaper', 'route' => 'admin.cms', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
            ],
        ],
    ],
];
