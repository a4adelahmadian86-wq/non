<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->string('replica_file_key', 512)->nullable()->after('replicated_to_volume_id');
        });
    }

    public function down(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->dropColumn('replica_file_key');
        });
    }
};
