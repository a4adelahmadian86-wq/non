<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // Privileged bootstrap is opt-in. Both the account identifier and the
        // initial password must be supplied by deployment configuration; the
        // repository itself must never define a reusable administrator identity.
        $phone = trim((string) env('FARAST_ADMIN_PHONE', env('ADMIN_MOBILE', '')));
        $password = (string) env('FARAST_ADMIN_PASSWORD', env('ADMIN_INITIAL_PASSWORD', ''));
        if ($phone === '' || $password === '') {
            return;
        }

        $user = User::where('mobile', $phone)->first();
        $user ??= new User(['mobile' => $phone]);
        $user->name = $user->name ?: 'مدیر اصلی';
        $user->role = 'admin';
        $user->is_verified = true;
        $user->is_blocked = false;
        if (! $user->password) {
            $user->password = Hash::make($password);
        }
        $user->save();
    }

    public function down(): void
    {
        // Never delete a privileged account automatically on rollback.
    }
};
