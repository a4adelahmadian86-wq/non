<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // Never create a privileged account from repository-owned credentials.
        // Account creation is handled by the earlier bootstrap migration only
        // when an administrator explicitly supplies FARAST_ADMIN_PASSWORD (or
        // ADMIN_INITIAL_PASSWORD). This migration may only preserve/promote an
        // already-existing, explicitly configured account.
        $mobile = trim((string) env('FARAST_ADMIN_PHONE', env('ADMIN_MOBILE', '')));
        if ($mobile === '') {
            return;
        }

        $existing = DB::table('users')->where('mobile', $mobile)->first();
        if (! $existing) {
            return;
        }

        DB::table('users')->where('mobile', $mobile)->update([
            'name' => $existing->name ?: 'مدیر اصلی',
            'role' => 'admin',
            'is_verified' => true,
            'is_blocked' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Intentionally do not delete or demote an administrator on rollback.
    }
};
