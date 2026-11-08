<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_ingestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('inspected');
            $table->string('staging_disk', 40)->default('private');
            $table->string('staging_path', 1024);
            $table->string('original_name', 255);
            $table->string('mime', 180);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->longText('inspection_json');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status', 'expires_at'], 'store_product_ingestions_state_idx');
            $table->index(['user_id', 'created_at'], 'store_product_ingestions_user_idx');
        });

        Schema::create('store_product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('product_file_id')->unique()->constrained('store_product_files')->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('processing_status', 24)->default('pending');
            $table->string('detected_type', 40)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedBigInteger('word_count')->default(0);
            $table->unsignedBigInteger('character_count')->default(0);
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('extraction_version')->default(1);
            $table->longText('structure_json')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'processing_status'], 'store_product_documents_status_idx');
            $table->index(['source_sha256', 'processing_status'], 'store_product_documents_hash_idx');
        });

        Schema::create('store_product_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('chunk_type', 32)->default('text');
            $table->longText('locator_json')->nullable();
            $table->longText('content_text');
            $table->char('content_sha256', 64);
            $table->longText('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'chunk_index'], 'store_document_chunks_order_unique');
            $table->index(['document_id', 'chunk_type'], 'store_document_chunks_type_idx');
            $table->index('content_sha256', 'store_document_chunks_hash_idx');
        });

        Schema::create('store_product_document_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('fact_type', 32)->default('other');
            $table->string('fact_key', 160);
            $table->longText('fact_value');
            $table->longText('evidence_text');
            $table->longText('locator_json')->nullable();
            $table->string('verification_status', 24)->default('evidence_backed');
            $table->timestamps();
            $table->index(['document_id', 'fact_type'], 'store_document_facts_type_idx');
            $table->index(['source_sha256', 'verification_status'], 'store_document_facts_hash_idx');
        });

        Schema::create('store_product_document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('page_kind', 32)->default('document_page');
            $table->string('locator_key', 190)->nullable();
            $table->longText('locator_json')->nullable();
            $table->longText('content_text');
            $table->longText('content_html');
            $table->char('content_sha256', 64);
            $table->boolean('has_extracted_text')->default(true);
            $table->timestamps();
            $table->unique(['document_id', 'page_number'], 'store_document_pages_order_unique');
            $table->index(['document_id', 'page_kind'], 'store_document_pages_kind_idx');
        });

        Schema::create('store_product_document_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_product_documents')->cascadeOnDelete();
            $table->string('artifact_type', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('disk', 40)->default('private');
            $table->string('path', 1024);
            $table->string('mime', 180);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64);
            $table->longText('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'artifact_type', 'version'], 'store_document_artifacts_version_unique');
            $table->index(['document_id', 'artifact_type'], 'store_document_artifacts_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_document_artifacts');
        Schema::dropIfExists('store_product_document_pages');
        Schema::dropIfExists('store_product_document_facts');
        Schema::dropIfExists('store_product_document_chunks');
        Schema::dropIfExists('store_product_documents');
        Schema::dropIfExists('store_product_ingestions');
    }
};
