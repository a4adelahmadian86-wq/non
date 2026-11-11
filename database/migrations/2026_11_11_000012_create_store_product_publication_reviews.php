<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_publication_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('store_products')->cascadeOnDelete();
            $table->foreignUuid('intake_session_id')->nullable()->constrained('store_product_intake_sessions')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending_ai_review');
            $table->string('ai_verdict', 32)->nullable();
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->longText('ai_review_json')->nullable();
            $table->foreignId('manager_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->text('manager_notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'store_publication_queue_state_idx');
            $table->index(['submitted_by', 'status'], 'store_publication_queue_submitter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_publication_reviews');
    }
};
