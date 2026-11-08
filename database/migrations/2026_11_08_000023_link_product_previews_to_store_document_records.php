<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            if (! Schema::hasColumn('store_product_previews', 'store_document_record_id')) {
                $table->foreignId('store_document_record_id')->nullable()->after('document_id')->constrained('store_document_records')->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('store_product_previews', 'store_product_previews_store_document_access_source_unique')) {
            Schema::table('store_product_previews', function (Blueprint $table) {
                $table->unique(['store_document_record_id', 'access_level', 'source_sha256'], 'store_product_previews_store_document_access_source_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            if (Schema::hasIndex('store_product_previews', 'store_product_previews_store_document_access_source_unique')) {
                $table->dropUnique('store_product_previews_store_document_access_source_unique');
            }
            if (Schema::hasColumn('store_product_previews', 'store_document_record_id')) {
                $table->dropConstrainedForeignId('store_document_record_id');
            }
        });
    }
};
