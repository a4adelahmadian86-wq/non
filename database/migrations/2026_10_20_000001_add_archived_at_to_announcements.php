<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('notified_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('announcements') || ! Schema::hasColumn('announcements', 'archived_at')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
