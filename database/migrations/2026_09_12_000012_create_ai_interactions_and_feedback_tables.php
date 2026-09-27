<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('typing_documents')->nullOnDelete();
            $table->string('provider', 40)->default('gemini');
            $table->string('model', 100);
            $table->string('operation', 80);
            $table->string('request_id', 100)->nullable()->index();
            $table->string('provider_interaction_id', 180)->nullable()->index();
            $table->string('source_hash', 64)->nullable()->index();
            $table->string('prompt_hash', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_bytes')->nullable();
            $table->unsignedInteger('output_bytes')->nullable();
            $table->json('input_meta')->nullable();
            $table->json('output_meta')->nullable();
            $table->string('status', 30)->default('started')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('typing_documents')->nullOnDelete();
            $table->foreignId('ai_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('category', 80)->nullable()->index();
            $table->text('original_text')->nullable();
            $table->text('corrected_text')->nullable();
            $table->text('note')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_interactions');
    }
};
