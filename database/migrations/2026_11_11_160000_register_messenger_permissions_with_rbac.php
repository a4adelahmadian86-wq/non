<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            throw new RuntimeException('RBAC permission tables must exist before messenger permissions are registered.');
        }

        $permissions = [
            'messenger.use' => 'استفاده از پیام‌رسان داخلی',
            'messenger.invite' => 'دعوت مخاطب در پیام‌رسان',
            'messenger.group.create' => 'ایجاد گروه پیام‌رسان',
            'messenger.group.manage' => 'مدیریت اعضای گروه پیام‌رسان',
        ];

        foreach ($permissions as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $label, 'group' => 'messenger', 'updated_at' => now(), 'created_at' => now()],
            );

            $permissionId = (int) DB::table('permissions')->where('key', $key)->value('id');
            foreach (['organization_manager', 'team_supervisor', 'employee'] as $role) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Permission grants are live authorization data; do not revoke them implicitly.
    }
};
