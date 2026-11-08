<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            if (! Schema::hasColumn('store_product_previews', 'product_document_page_id')) {
                $table->foreignId('product_document_page_id')
                    ->nullable()
                    ->after('document_id')
                    ->constrained('store_product_document_pages')
                    ->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('store_product_previews', 'store_previews_document_page_access_unique')) {
            Schema::table('store_product_previews', function (Blueprint $table) {
                $table->unique(
                    ['product_document_page_id', 'access_level'],
                    'store_previews_document_page_access_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('store_product_previews', 'store_previews_document_page_access_unique')) {
            Schema::table('store_product_previews', function (Blueprint $table) {
                $table->dropUnique('store_previews_document_page_access_unique');
            });
        }

        if (Schema::hasColumn('store_product_previews', 'product_document_page_id')) {
            Schema::table('store_product_previews', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_document_page_id');
            });
        }
    }
};
