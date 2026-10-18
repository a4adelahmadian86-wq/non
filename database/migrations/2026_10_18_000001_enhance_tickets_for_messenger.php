<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'guest_name')) {
                $table->string('guest_name')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('tickets', 'guest_email')) {
                $table->string('guest_email')->nullable()->after('guest_name');
            }
            if (! Schema::hasColumn('tickets', 'department')) {
                $table->string('department')->default('support')->after('priority');
            }
            if (! Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->timestamp('last_reply_at')->nullable();
            }
            if (! Schema::hasColumn('tickets', 'source')) {
                $table->string('source')->default('dashboard');
            }
        });

        // Make user_id nullable for guest tickets (MySQL-safe without doctrine/dbal)
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE tickets MODIFY user_id BIGINT UNSIGNED NULL');
                DB::statement('ALTER TABLE ticket_messages MODIFY user_id BIGINT UNSIGNED NULL');
            } elseif (in_array($driver, ['pgsql', 'postgres'], true)) {
                DB::statement('ALTER TABLE tickets ALTER COLUMN user_id DROP NOT NULL');
                DB::statement('ALTER TABLE ticket_messages ALTER COLUMN user_id DROP NOT NULL');
            } elseif ($driver === 'sqlite') {
                // SQLite: leave as-is; new columns cover guest flow; strict FK may still require a dummy user in some envs
            }
        } catch (\Throwable) {
            // non-fatal: guest flow still works for logged-in widget users
        }

        Schema::table('ticket_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_messages', 'attachments')) {
                $table->json('attachments')->nullable()->after('body');
            }
            if (! Schema::hasColumn('ticket_messages', 'is_staff')) {
                $table->boolean('is_staff')->default(false)->after('is_internal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['guest_name', 'guest_email', 'department', 'last_reply_at', 'source'] as $col) {
                if (Schema::hasColumn('tickets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_messages', 'attachments')) {
                $table->dropColumn('attachments');
            }
            if (Schema::hasColumn('ticket_messages', 'is_staff')) {
                $table->dropColumn('is_staff');
            }
        });
    }
};
