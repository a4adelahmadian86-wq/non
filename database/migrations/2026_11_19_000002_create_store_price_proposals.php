<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_price_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->restrictOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('base_price_rials');
            $table->unsignedBigInteger('campaign_price_rials')->nullable();
            $table->unsignedBigInteger('cost_price_rials')->nullable();
            $table->unsignedBigInteger('minimum_margin_rials')->default(0);
            $table->char('source_hash', 64);
            $table->json('snapshot_before');
            $table->json('snapshot_after')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status', 'created_at'], 'store_price_proposals_product_state_idx');
            $table->index(['uploader_id', 'status', 'created_at'], 'store_price_proposals_uploader_state_idx');
        });
    }

    public function down(): void
    {
        // Proposal and decision history is auditable business data; a code rollback
        // must not erase it. The additive table is intentionally retained.
    }
};
