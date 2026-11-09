<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('announcements') || Schema::hasColumn('announcements', 'broadcast_revision')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->unsignedInteger('broadcast_revision')->default(1);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('announcements') || ! Schema::hasColumn('announcements', 'broadcast_revision')) {
            return;
        }

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('broadcast_revision');
        });
    }
};
