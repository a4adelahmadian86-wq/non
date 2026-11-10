<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('drive_key', 40)->index();
            $table->string('name');
            $table->string('provider')->nullable();
            $table->string('driver', 30)->default('s3');
            $table->string('region', 80)->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->string('bucket')->nullable();
            $table->longText('credentials')->nullable();
            $table->unsignedBigInteger('capacity_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('file_limit')->nullable();
            $table->unsignedBigInteger('max_file_bytes')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('status', 30)->default('unverified')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('storage_managed_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_volume_id')->constrained('storage_volumes')->restrictOnDelete();
            $table->string('drive_key', 40)->index();
            $table->string('file_key', 1024);
            $table->string('original_name');
            $table->string('mime', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable()->index();
            $table->string('classification', 40)->default('non_critical')->index();
            $table->string('source_module', 80)->nullable()->index();
            $table->string('source_type', 120)->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('visibility', 30)->default('private');
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('replicated_to_volume_id')->nullable()->constrained('storage_volumes')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['storage_volume_id', 'file_key']);
            $table->index(['drive_key', 'status', 'created_at']);
        });

        Schema::create('storage_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_volume_id')->nullable()->constrained('storage_volumes')->nullOnDelete();
            $table->string('backup_type', 40)->default('weekly');
            $table->string('source_name', 120)->default('application');
            $table->string('status', 30)->default('queued')->index();
            $table->string('object_key', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['backup_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_backup_runs');
        Schema::dropIfExists('storage_managed_files');
        Schema::dropIfExists('storage_volumes');
    }
};