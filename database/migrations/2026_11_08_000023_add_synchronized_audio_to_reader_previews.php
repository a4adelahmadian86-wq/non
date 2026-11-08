<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->string('audio_disk', 40)->nullable()->after('source_sha256');
            $table->string('audio_path', 500)->nullable()->after('audio_disk');
            $table->string('audio_mime', 120)->nullable()->after('audio_path');
            $table->json('audio_timing')->nullable()->after('audio_mime');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->dropColumn(['audio_disk', 'audio_path', 'audio_mime', 'audio_timing']);
        });
    }
};
