<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('farast_notifications') || Schema::hasColumn('farast_notifications', 'dedupe_key')) {
            return;
        }

        Schema::table('farast_notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->unique('farast_notifications_dedupe_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('farast_notifications') || ! Schema::hasColumn('farast_notifications', 'dedupe_key')) {
            return;
        }

        Schema::table('farast_notifications', function (Blueprint $table) {
            $table->dropUnique('farast_notifications_dedupe_key_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};
