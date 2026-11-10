<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_managed_files', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_volume_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Staged files may legitimately have no remote volume. Do not make this
        // column non-nullable again because that would make rollback destructive.
    }
};