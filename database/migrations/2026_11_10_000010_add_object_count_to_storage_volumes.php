<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_volumes', function (Blueprint $table) {
            $table->unsignedBigInteger('object_count')->default(0)->after('used_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('storage_volumes', function (Blueprint $table) {
            $table->dropColumn('object_count');
        });
    }
};