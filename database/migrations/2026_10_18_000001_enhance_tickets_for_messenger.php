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

        Schema::table('ticket_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_messages', 'attachments')) {
                $table->json('attachments')->nullable()->after('body');
            }
            if (! Schema::hasColumn('ticket_messages', 'is_staff')) {
                $table->boolean('is_staff')->default(false)->after('is_internal');
            }
        });

        // Allow guest tickets/messages (nullable user_id). Drop FKs first on MySQL.
        try {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'mysql') {
                $this->dropForeignKeyIfExists('tickets', 'tickets_user_id_foreign');
                $this->dropForeignKeyIfExists('ticket_messages', 'ticket_messages_user_id_foreign');

                DB::statement('ALTER TABLE tickets MODIFY user_id BIGINT UNSIGNED NULL');
                DB::statement('ALTER TABLE ticket_messages MODIFY user_id BIGINT UNSIGNED NULL');

                Schema::table('tickets', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                });
                Schema::table('ticket_messages', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                });
            } elseif (in_array($driver, ['pgsql', 'postgres'], true)) {
                DB::statement('ALTER TABLE tickets ALTER COLUMN user_id DROP NOT NULL');
                DB::statement('ALTER TABLE ticket_messages ALTER COLUMN user_id DROP NOT NULL');
            }
        } catch (\Throwable $e) {
            // Non-fatal: logged-in support still works even if guest nullability fails.
            report($e);
        }
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

    private function dropForeignKeyIfExists(string $table, string $keyName): void
    {
        try {
            $db = Schema::getConnection()->getDatabaseName();
            $exists = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
                [$db, $table, $keyName, 'FOREIGN KEY']
            );
            if ($exists) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$keyName}`");
            }
        } catch (\Throwable) {
            // ignore
        }
    }
};
