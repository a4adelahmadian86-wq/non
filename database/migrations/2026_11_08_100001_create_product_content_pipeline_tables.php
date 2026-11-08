<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('state', 32)->default('awaiting_confirmation')->index();
            $table->string('original_name', 255);
            $table->string('detected_mime', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable()->index();
            $table->string('quarantine_disk', 50)->default('private');
            $table->string('quarantine_path', 1024);
            $table->json('inspection')->nullable();
            $table->json('security_report')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('store_product_upload_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained('store_product_upload_sessions')->cascadeOnDelete();
            $table->string('stage', 48);
            $table->string('state', 24);
            $table->string('title', 160);
            $table->text('detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['upload_session_id', 'id']);
        });

        Schema::create('store_product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('product_file_id')->nullable()->constrained('store_product_files')->nullOnDelete();
            $table->foreignId('upload_session_id')->nullable()->constrained('store_product_upload_sessions')->nullOnDelete();
            $table->char('source_sha256', 64)->index();
            $table->string('original_name', 255);
            $table->string('detected_mime', 160)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_disk', 50)->default('private');
            $table->string('storage_path', 1024);
            $table->string('reader_asset_disk', 50)->nullable();
            $table->string('reader_asset_path', 1024)->nullable();
            $table->string('reader_asset_mime', 160)->nullable();
            $table->string('reader_asset_kind', 24)->nullable();
            $table->string('processing_state', 32)->default('uploaded')->index();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('content_version')->default(1);
            $table->json('technical_metadata')->nullable();
            $table->json('content_manifest')->nullable();
            $table->timestamp('content_studied_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'source_sha256']);
        });

        Schema::create('store_product_document_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('block_type', 32)->default('paragraph');
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_path', 512)->nullable();
            $table->longText('text_content')->nullable();
            $table->json('metadata')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'sequence']);
            $table->index(['document_id', 'page_number']);
            $table->index(['document_id', 'block_type']);
        });

        Schema::create('store_product_document_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->string('storage_disk', 50)->default('private');
            $table->string('storage_path', 1024);
            $table->string('mime', 160);
            $table->json('selected_pages')->nullable();
            $table->string('sample_format', 20)->nullable();
            $table->string('state', 24)->default('ready');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique('document_id');
        });

        Schema::create('store_product_document_audio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->string('storage_disk', 50)->default('private');
            $table->string('storage_path', 1024);
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('state', 24)->default('pending');
            $table->string('voice_profile', 80)->nullable();
            $table->unsignedInteger('content_version')->default(1);
            $table->json('alignment_metadata')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique('document_id');
        });

        Schema::create('store_product_document_audio_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_id')->constrained('store_product_document_audio')->cascadeOnDelete();
            $table->foreignId('document_block_id')->nullable()->constrained('store_product_document_blocks')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->json('visual_locator')->nullable();
            $table->timestamps();
            $table->unique(['audio_id', 'sequence']);
            $table->index(['audio_id', 'start_ms', 'end_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_document_audio_segments');
        Schema::dropIfExists('store_product_document_audio');
        Schema::dropIfExists('store_product_document_samples');
        Schema::dropIfExists('store_product_document_blocks');
        Schema::dropIfExists('store_product_documents');
        Schema::dropIfExists('store_product_upload_events');
        Schema::dropIfExists('store_product_upload_sessions');
    }
};
