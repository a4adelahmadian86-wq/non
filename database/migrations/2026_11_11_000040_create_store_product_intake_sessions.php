<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_intake_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_type', 24);
            $table->string('status', 32)->default('selecting_files');
            $table->json('metadata')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at'], 'store_product_intake_owner_state_idx');
            $table->index(['product_id', 'status'], 'store_product_intake_product_state_idx');
        });

        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreignId('product_id')->nullable()->change();
            $table->foreignUuid('intake_session_id')->nullable()->after('product_id');
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->foreignId('managed_file_id')->nullable()->constrained('storage_managed_files')->nullOnDelete();
            $table->string('supervisor_status', 32)->default('pending');
            $table->longText('supervisor_review_json')->nullable();
            $table->timestamp('supervisor_reviewed_at')->nullable();
            $table->string('antivirus_status', 24)->default('not_requested');
            $table->string('antivirus_engine', 80)->nullable();
            $table->timestamp('antivirus_scanned_at')->nullable();

            $table->foreign('product_id')->references('id')->on('store_products')->cascadeOnDelete();
            $table->foreign('intake_session_id')->references('id')->on('store_product_intake_sessions')->cascadeOnDelete();
            $table->index(['intake_session_id', 'status'], 'store_product_ingestions_intake_state_idx');
            $table->index(['supervisor_status', 'created_at'], 'store_product_ingestions_supervisor_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('store_product_ingestions')
            && DB::table('store_product_ingestions')->whereNull('product_id')->whereNotNull('intake_session_id')->exists()) {
            throw new RuntimeException('Cannot roll back intake sessions while product-less intake records still exist.');
        }

        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['intake_session_id']);
            $table->dropForeign(['managed_file_id']);
            $table->dropIndex('store_product_ingestions_intake_state_idx');
            $table->dropIndex('store_product_ingestions_supervisor_idx');
            $table->dropColumn([
                'intake_session_id',
                'storage_disk',
                'storage_path',
                'managed_file_id',
                'supervisor_status',
                'supervisor_review_json',
                'supervisor_reviewed_at',
                'antivirus_status',
                'antivirus_engine',
                'antivirus_scanned_at',
            ]);
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('store_products')->cascadeOnDelete();
        });

        Schema::dropIfExists('store_product_intake_sessions');
    }
};
