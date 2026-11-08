<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('product_id')
                ->constrained('store_product_documents')->nullOnDelete();
            $table->index(['product_id', 'document_id', 'access_level']);
        });
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'document_id', 'access_level']);
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
