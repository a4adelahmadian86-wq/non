<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('farast_notifications')) {
            return;
        }

        if (! Schema::hasColumn('farast_notifications', 'archived_at')) {
            Schema::table('farast_notifications', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }

        Schema::table('farast_notifications', function (Blueprint $table) {
            if (! collect(Schema::getIndexes('farast_notifications'))->contains(fn ($index) => ($index['name'] ?? null) === 'farast_notifications_user_archive_idx')) {
                $table->index(['user_id', 'archived_at', 'created_at'], 'farast_notifications_user_archive_idx');
            }
        });
    }

    public function down(): void
    {
        // Notification history is a shared ledger. Keep archived state and index on rollback.
    }
};
