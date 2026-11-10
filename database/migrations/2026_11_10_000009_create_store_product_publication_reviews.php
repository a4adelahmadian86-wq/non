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
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('verdict', 24)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('review_id', 100)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('reviewed_snapshot_hash', 64)->nullable();
            $table->longText('snapshot_json')->nullable();
            $table->longText('findings_json')->nullable();
            $table->longText('corrections_json')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('auto_publish')->default(false);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at'], 'store_product_publication_state_idx');
            $table->index(['status', 'updated_at'], 'store_product_publication_queue_idx');
            $table->index('snapshot_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_publication_reviews');
    }
};
