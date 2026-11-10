<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->dropUnique(['storage_volume_id', 'file_key']);
            $table->dropUnique(['logical_path']);
            $table->string('file_key', 512)->change();
            $table->string('logical_path', 512)->nullable()->change();
            $table->unique(['storage_volume_id', 'file_key']);
            $table->unique('logical_path');
        });
    }

    public function down(): void
    {
        // Deliberately keep the safe index widths when rolling back application code.
    }
};
