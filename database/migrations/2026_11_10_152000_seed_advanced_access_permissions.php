<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permissions = [
            ['key' => 'access.roles.manage', 'label' => 'ایجاد و مدیریت نقش‌ها', 'group' => 'access'],
            ['key' => 'access.assign', 'label' => 'انتساب نقش به کاربران', 'group' => 'access'],
            ['key' => 'access.override', 'label' => 'مدیریت دسترسی مستقیم کاربران', 'group' => 'access'],
            ['key' => 'access.audit', 'label' => 'مشاهده تاریخچه دسترسی', 'group' => 'access'],
            ['key' => 'workflows.create', 'label' => 'ایجاد گردش‌کار', 'group' => 'workflows'],
            ['key' => 'workflows.edit', 'label' => 'ویرایش گردش‌کار', 'group' => 'workflows'],
            ['key' => 'workflows.delete', 'label' => 'حذف گردش‌کار', 'group' => 'workflows'],
            ['key' => 'workflows.run', 'label' => 'اجرای گردش‌کار', 'group' => 'workflows'],
            ['key' => 'workflows.audit', 'label' => 'مشاهده تاریخچه گردش‌کار', 'group' => 'workflows'],
            ['key' => 'automation.manage', 'label' => 'مدیریت اتوماسیون', 'group' => 'automation'],
            ['key' => 'automation.run', 'label' => 'اجرای اتوماسیون', 'group' => 'automation'],
            ['key' => 'automation.audit', 'label' => 'ممیزی اتوماسیون', 'group' => 'automation'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                ...$permission,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Keep catalog entries on rollback: roles and audit history may already
        // reference them, and deleting a permission would silently alter access.
    }
};
