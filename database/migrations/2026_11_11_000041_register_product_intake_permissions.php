<?php

use App\\Services\\AuthorizationService;
use Illuminate\\Database\\Migrations\\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The original permission-sync migration has already run on existing
        // installations. Re-run the idempotent source-of-truth sync so the
        // intake routes and reviewer queue are usable without manual DB edits.
        app(AuthorizationService::class)->syncDefaults();
    }

    public function down(): void
    {
        // Permission and role-permission rows are shared authorization data.
        // They are intentionally retained to avoid revoking assignments on rollback.
    }
};
