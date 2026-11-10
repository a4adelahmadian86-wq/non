<?php

/**
 * Navigation is organized by the user's work, not by controller or implementation
 * history. Only implemented, directly navigable destinations belong here.
 * DashboardNavigationService applies role and permission filtering and omits
 * planned or unavailable routes.
 */
return [
    'navigation' => [
        [
            'key' => 'home',
            'label' => 'خانه و میز کار',
            'icon' => 'fa-house',
            'items' => [
                ['key' => 'home.overview', 'label' => 'نمای کلی', 'icon' => 'fa-grid-2', 'route' => 'dashboard', 'active_unless_query' => ['status']],
                ['key' => 'home.drafts', 'label' => 'پیش‌نویس‌ها', 'icon' => 'fa-file-pen', 'route' => 'dashboard', 'query' => ['status' => 'draft']],
                ['key' => 'home.notifications', 'label' => 'اعلان‌های من', 'icon' => 'fa-bell', 'route' => 'platform.notifications'],
            ],
        ],
        [
            'key' => 'content',
            'label' => 'اسناد و کتابخانه',
            'icon' => 'fa-book-open',
            'items' => [
                ['key' => 'content.documents', 'label' => 'مدیریت اسناد', 'icon' => 'fa-file-lines', 'route' => 'platform.documents', 'active_unless_query' => ['favorite', 'trashed', 'shared', 'folder'], 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'content.folders', 'label' => 'پوشه‌ها', 'icon' => 'fa-folder-tree', 'route' => 'platform.documents', 'fragment' => '#folder-panel', 'active_when_query' => ['folder'], 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'content.shared', 'label' => 'اسناد اشتراکی', 'icon' => 'fa-share-nodes', 'route' => 'platform.documents', 'query' => ['shared' => 1], 'permission' => 'documents.share', 'scope' => 'own'],
                ['key' => 'content.favorites', 'label' => 'علاقه‌مندی‌ها', 'icon' => 'fa-star', 'route' => 'platform.documents', 'query' => ['favorite' => 1], 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'content.deleted', 'label' => 'حذف‌شده‌ها', 'icon' => 'fa-trash-can', 'route' => 'platform.documents', 'query' => ['trashed' => 1], 'permission' => 'documents.delete', 'scope' => 'own'],
                ['key' => 'content.files', 'label' => 'فایل‌های من', 'icon' => 'fa-folder-open', 'route' => 'editor.files.index', 'permission' => 'documents.view', 'scope' => 'own'],
                ['key' => 'content.library', 'label' => 'کتابخانه خریداری‌شده', 'icon' => 'fa-book-bookmark', 'route' => 'library'],
                ['key' => 'content.wishlist', 'label' => 'فهرست علاقه‌مندی فروشگاه', 'icon' => 'fa-heart', 'route' => 'wishlist'],
            ],
        ],
        [
            'key' => 'creation',
            'label' => 'تولید و ویرایش',
            'icon' => 'fa-pen-ruler',
            'items' => [
                ['key' => 'creation.editor', 'label' => 'شروع ویرایش', 'icon' => 'fa-pen-to-square', 'route' => 'editor', 'permission' => 'documents.create', 'scope' => 'own'],
                ['key' => 'creation.projects', 'label' => 'ایجاد پروژه', 'icon' => 'fa-folder-plus', 'route' => 'projects.create', 'permission' => 'editor.use', 'scope' => 'own'],
                ['key' => 'creation.ai.history', 'label' => 'تاریخچه دستیار هوشمند', 'icon' => 'fa-clock-rotate-left', 'route' => 'assistant.history', 'permission' => 'ai.use', 'scope' => 'own'],
                ['key' => 'creation.ai.memories', 'label' => 'حافظه دستیار', 'icon' => 'fa-brain', 'route' => 'assistant.memories', 'permission' => 'ai.use', 'scope' => 'own'],
            ],
        ],
        [
            'key' => 'automation',
            'label' => 'گردش کار و اتوماسیون',
            'icon' => 'fa-diagram-project',
            'items' => [
                ['key' => 'automation.workflows', 'label' => 'گردش‌های کار', 'icon' => 'fa-route', 'route' => 'platform.workflows', 'permission' => 'workflows.view', 'scope' => 'team'],
            ],
        ],
        [
            'key' => 'account',
            'label' => 'مالی و اشتراک',
            'icon' => 'fa-wallet',
            'items' => [
                ['key' => 'account.wallet', 'label' => 'کیف پول و تراکنش‌ها', 'icon' => 'fa-wallet', 'route' => 'wallet'],
                ['key' => 'account.subscription', 'label' => 'اشتراک و طرح‌ها', 'icon' => 'fa-crown', 'route' => 'platform.billing', 'permission' => 'billing.view', 'scope' => 'own'],
                ['key' => 'account.admin.finance', 'label' => 'مدیریت مالی پلتفرم', 'icon' => 'fa-coins', 'route' => 'admin.finance', 'admin_only' => true, 'permission' => 'finance.view', 'scope' => 'organization'],
                ['key' => 'account.admin.plans', 'label' => 'مدیریت طرح‌ها', 'icon' => 'fa-tags', 'route' => 'admin.plans', 'admin_only' => true, 'permission' => 'billing.manage', 'scope' => 'global'],
            ],
        ],
        [
            'key' => 'communication',
            'label' => 'ارتباطات و پشتیبانی',
            'icon' => 'fa-comments',
            'items' => [
                ['key' => 'communication.announcements', 'label' => 'اطلاعیه‌ها', 'icon' => 'fa-bullhorn', 'route' => 'announcements'],
                ['key' => 'communication.support', 'label' => 'درخواست‌های پشتیبانی من', 'icon' => 'fa-headset', 'route' => 'support', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'communication.support.new', 'label' => 'ثبت درخواست پشتیبانی', 'icon' => 'fa-plus', 'route' => 'support.create.form', 'permission' => 'support.use', 'scope' => 'own'],
                ['key' => 'communication.admin.support', 'label' => 'مدیریت تیکت‌ها', 'icon' => 'fa-inbox', 'route' => 'admin.support.management', 'admin_only' => true],
                ['key' => 'communication.admin.support.report', 'label' => 'گزارش پشتیبانی', 'icon' => 'fa-chart-line', 'route' => 'admin.support.report', 'admin_only' => true],
                ['key' => 'communication.admin.support.sms', 'label' => 'تنظیمات پیامک', 'icon' => 'fa-comment-sms', 'route' => 'admin.support.sms', 'admin_only' => true],
                ['key' => 'communication.admin.announcements', 'label' => 'مدیریت اطلاعیه‌ها', 'icon' => 'fa-bullhorn', 'route' => 'admin.announcements.index', 'admin_only' => true],
            ],
        ],
        [
            'key' => 'store_crm',
            'label' => 'فروشگاه و مشتریان',
            'icon' => 'fa-store',
            'admin_only' => true,
            'items' => [
                ['key' => 'store.products', 'label' => 'مدیریت محصولات و دوره‌ها', 'icon' => 'fa-boxes-stacked', 'route' => 'admin.store.index'],
                ['key' => 'store.product.create', 'label' => 'افزودن محصول', 'icon' => 'fa-circle-plus', 'route' => 'admin.store.create'],
                ['key' => 'store.operations', 'label' => 'مرکز عملیات محصولات', 'icon' => 'fa-gears', 'route' => 'admin.store.operations'],
                ['key' => 'crm.customers', 'label' => 'مشتریان', 'icon' => 'fa-users', 'route' => 'admin.crm.customers.index'],
                ['key' => 'crm.projects', 'label' => 'پرونده‌ها و پروژه‌های مشتریان', 'icon' => 'fa-folder-tree', 'route' => 'admin.crm.projects.index'],
                ['key' => 'crm.followups', 'label' => 'پیگیری‌های مشتریان', 'icon' => 'fa-list-check', 'route' => 'admin.crm.followups.index'],
                ['key' => 'crm.segments', 'label' => 'بخش‌بندی مخاطبان', 'icon' => 'fa-filter', 'route' => 'admin.crm.marketing.segments.index'],
                ['key' => 'crm.campaigns', 'label' => 'کمپین‌های ارتباطی', 'icon' => 'fa-bullhorn', 'route' => 'admin.crm.marketing.campaigns.index'],
                ['key' => 'crm.analytics', 'label' => 'تحلیل مشتریان', 'icon' => 'fa-chart-pie', 'route' => 'admin.crm.analytics'],
                ['key' => 'store.discussions', 'label' => 'مدیریت گفتگوهای جامعه', 'icon' => 'fa-comments', 'route' => 'admin.discussions.index'],
                ['key' => 'store.discussion.reports', 'label' => 'گزارش‌های گفتگو', 'icon' => 'fa-flag', 'route' => 'admin.discussion-reports.index'],
            ],
        ],
        [
            'key' => 'organization',
            'label' => 'کاربران و سازمان',
            'icon' => 'fa-building',
            'admin_only' => true,
            'items' => [
                ['key' => 'organization.users', 'label' => 'مدیریت کاربران', 'icon' => 'fa-users', 'route' => 'admin.users', 'permission' => 'users.view', 'scope' => 'organization'],
                ['key' => 'organization.organizations', 'label' => 'سازمان‌ها و تیم‌ها', 'icon' => 'fa-building', 'route' => 'admin.organizations', 'permission' => 'organization.manage', 'scope' => 'organization'],
                ['key' => 'organization.access', 'label' => 'نقش‌ها و مجوزها', 'icon' => 'fa-user-shield', 'route' => 'admin.access', 'permission' => 'access.manage', 'scope' => 'global'],
            ],
        ],
        [
            'key' => 'insights',
            'label' => 'گزارش‌ها و نظارت',
            'icon' => 'fa-chart-line',
            'admin_only' => true,
            'items' => [
                ['key' => 'insights.analytics', 'label' => 'تحلیل و گزارش عملکرد', 'icon' => 'fa-chart-pie', 'route' => 'admin.analytics', 'permission' => 'reports.team', 'scope' => 'team'],
                ['key' => 'insights.audit', 'label' => 'گزارش ممیزی', 'icon' => 'fa-clipboard-check', 'route' => 'admin.audit', 'permission' => 'audit.view', 'scope' => 'global'],
            ],
        ],
        [
            'key' => 'settings',
            'label' => 'امنیت و تنظیمات',
            'icon' => 'fa-sliders',
            'items' => [
                ['key' => 'settings.security', 'label' => 'امنیت فضای کاری', 'icon' => 'fa-shield-halved', 'route' => 'platform.security'],
                ['key' => 'settings.integrations', 'label' => 'یکپارچه‌سازی‌ها', 'icon' => 'fa-plug', 'route' => 'admin.integrations', 'admin_only' => true, 'permission' => 'integrations.manage', 'scope' => 'global'],
                ['key' => 'settings.email', 'label' => 'تنظیمات ایمیل', 'icon' => 'fa-envelope', 'route' => 'admin.emails', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
                ['key' => 'settings.social', 'label' => 'شبکه‌های اجتماعی', 'icon' => 'fa-share-nodes', 'route' => 'admin.social', 'admin_only' => true],
                ['key' => 'settings.cms', 'label' => 'مدیریت محتوای سایت', 'icon' => 'fa-newspaper', 'route' => 'admin.cms', 'admin_only' => true, 'permission' => 'settings.manage', 'scope' => 'global'],
            ],
        ],
    ],
];
