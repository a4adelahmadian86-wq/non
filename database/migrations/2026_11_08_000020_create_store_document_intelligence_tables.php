<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_file_intakes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('private');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 160)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 32)->default('awaiting_confirmation');
            $table->json('inspection_json')->nullable();
            $table->foreignId('confirmed_file_id')->nullable()->constrained('store_product_files')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['product_id', 'status']);
            $table->index(['product_id', 'sha256']);
        });

        Schema::create('store_document_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('file_id')->unique()->constrained('store_product_files')->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('detected_mime', 160)->nullable();
            $table->string('detected_type', 40)->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedBigInteger('extracted_characters')->default(0);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('content_version')->default(1);
            $table->unsignedInteger('knowledge_version')->default(0);
            $table->json('metadata')->nullable();
            $table->text('last_error_code')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
            $table->index(['source_sha256', 'content_version']);
        });

        Schema::create('store_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_document_records')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('content_type', 32)->default('text');
            $table->unsignedInteger('page_number')->nullable();
            $table->string('heading', 500)->nullable();
            $table->longText('content');
            $table->json('structured_content')->nullable();
            $table->json('source_anchor')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->unique(['document_id', 'chunk_index']);
            $table->index(['document_id', 'page_number']);
            $table->index(['document_id', 'content_type']);
        });

        Schema::create('store_document_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_document_records')->cascadeOnDelete();
            $table->string('fact_key', 180);
            $table->longText('fact_value');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('evidence')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('knowledge_version')->default(1);
            $table->timestamps();
            $table->index(['document_id', 'fact_key']);
        });

        Schema::create('store_document_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_document_records')->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('disk', 40)->default('private');
            $table->string('path', 500);
            $table->string('mime', 160);
            $table->string('kind', 32);
            $table->json('selected_pages')->nullable();
            $table->string('status', 24)->default('ready');
            $table->timestamps();
            $table->unique(['document_id', 'source_sha256']);
        });

        Schema::create('store_document_audio_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('store_document_records')->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('disk', 40)->default('private');
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('status', 24)->default('ready');
            $table->string('provider', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'source_sha256']);
        });

        Schema::create('store_document_audio_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('store_document_audio_tracks')->cascadeOnDelete();
            $table->unsignedInteger('segment_index');
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->longText('text')->nullable();
            $table->json('source_anchor')->nullable();
            $table->timestamps();
            $table->unique(['track_id', 'segment_index']);
            $table->index(['track_id', 'start_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_document_audio_segments');
        Schema::dropIfExists('store_document_audio_tracks');
        Schema::dropIfExists('store_document_samples');
        Schema::dropIfExists('store_document_facts');
        Schema::dropIfExists('store_document_chunks');
        Schema::dropIfExists('store_document_records');
        Schema::dropIfExists('store_file_intakes');
    }
};
