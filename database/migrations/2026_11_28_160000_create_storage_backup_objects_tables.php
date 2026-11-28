<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_backup_objects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_volume_id')->constrained('storage_volumes')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('object_key', 255)->unique();
            $table->unsignedBigInteger('encrypted_size_bytes')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['storage_volume_id', 'sha256'], 'storage_backup_objects_volume_sha_unique');
            $table->index(['storage_volume_id', 'status']);
        });

        Schema::create('storage_backup_run_objects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storage_backup_run_id')->constrained('storage_backup_runs')->cascadeOnDelete();
            $table->foreignId('storage_backup_object_id')->constrained('storage_backup_objects')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['storage_backup_run_id', 'storage_backup_object_id'], 'storage_backup_run_objects_unique');
            $table->index('storage_backup_object_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_backup_run_objects');
        Schema::dropIfExists('storage_backup_objects');
    }
};
