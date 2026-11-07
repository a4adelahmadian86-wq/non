<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guest support stores null actors. The older migration attempted this
        // only on MySQL/PostgreSQL and silently skipped SQLite, leaving schema
        // inconsistent with SupportTicketService.
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'user_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('ticket_messages') && Schema::hasColumn('ticket_messages', 'user_id')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: making guest actor references NOT NULL
        // would require deleting or fabricating owners for legitimate guest records.
    }
};
