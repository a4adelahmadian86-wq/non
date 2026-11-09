<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_documents', function (Blueprint $table) {
            $table->index(['processing_status', 'updated_at'], 'store_product_documents_recovery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_documents', function (Blueprint $table) {
            $table->dropIndex('store_product_documents_recovery_idx');
        });
    }
};
