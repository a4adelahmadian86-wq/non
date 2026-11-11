<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_intake_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->string('product_type', 32);
            $table->string('status', 32)->default('selecting_files');
            $table->boolean('antivirus_requested')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at'], 'store_intake_user_state_idx');
            $table->index(['product_id', 'status'], 'store_intake_product_state_idx');
        });

        Schema::create('store_product_intake_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('intake_session_id')->constrained('store_product_intake_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('inspected');
            $table->string('staging_disk', 40)->default('private');
            $table->string('staging_path', 1024)->nullable();
            $table->string('managed_disk', 80)->nullable();
            $table->string('managed_path', 1024)->nullable();
            $table->unsignedBigInteger('managed_file_id')->nullable();
            $table->foreignId('product_file_id')->nullable()->constrained('store_product_files')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('mime', 180);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->longText('inspection_json');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['intake_session_id', 'status'], 'store_intake_files_state_idx');
            $table->index(['sha256', 'status'], 'store_intake_files_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_intake_files');
        Schema::dropIfExists('store_product_intake_sessions');
    }
};
