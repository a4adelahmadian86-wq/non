<?php

use App\Services\AuthorizationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        \Illuminate\Support\Facades\DB::table('users')
            ->where('role', 'user')
            ->update(['role' => 'employee']);

        app(AuthorizationService::class)->syncDefaults();
    }

    public function down(): void
    {
        // Permission grants are additive defaults and are intentionally not revoked
        // during rollback to avoid removing manually relied-upon access.
    }
};
