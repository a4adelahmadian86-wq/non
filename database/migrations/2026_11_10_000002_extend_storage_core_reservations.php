<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_volumes', function (Blueprint $table) {
            $table->unsignedBigInteger('reserved_bytes')->default(0)->after('used_bytes');
            $table->unsignedBigInteger('reserved_files')->default(0)->after('reserved_bytes');
        });

        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->string('logical_path', 1024)->nullable()->unique()->after('storage_volume_id');
            $table->foreignId('owner_user_id')->nullable()->after('product_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropColumn(['logical_path', 'owner_user_id']);
        });

        Schema::table('storage_volumes', function (Blueprint $table) {
            $table->dropColumn(['reserved_bytes', 'reserved_files']);
        });
    }
};