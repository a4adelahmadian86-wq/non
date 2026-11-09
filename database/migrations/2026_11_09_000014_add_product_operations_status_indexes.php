<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_documents', function (Blueprint $table) {
            $table->index(
                ['processing_status', 'updated_at'],
                'store_product_documents_status_updated_idx'
            );
        });

        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->index(
                ['status', 'expires_at'],
                'store_product_ingestions_status_expiry_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropIndex('store_product_ingestions_status_expiry_idx');
        });

        Schema::table('store_product_documents', function (Blueprint $table) {
            $table->dropIndex('store_product_documents_status_updated_idx');
        });
    }
};
