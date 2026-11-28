<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_backup_usage_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_volume_id')->constrained('storage_volumes')->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('class_a_operations')->default(0);
            $table->unsignedBigInteger('class_b_operations')->default(0);
            $table->unsignedBigInteger('source_read_operations')->default(0);
            $table->unsignedBigInteger('source_read_bytes')->default(0);
            $table->unsignedBigInteger('uploaded_bytes')->default(0);
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['storage_volume_id', 'period_start'], 'storage_backup_usage_volume_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_backup_usage_periods');
    }
};
