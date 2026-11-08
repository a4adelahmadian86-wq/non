<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            if (! Schema::hasColumn('store_product_previews', 'document_id')) {
                $table->foreignId('document_id')->nullable()->after('product_id')->constrained('store_document_records')->nullOnDelete();
            }
            if (! Schema::hasColumn('store_product_previews', 'source_sha256')) {
                $table->char('source_sha256', 64)->nullable()->after('document_id');
            }
        });

        if (! Schema::hasIndex('store_product_previews', 'store_product_previews_document_access_source_unique')) {
            Schema::table('store_product_previews', function (Blueprint $table) {
                $table->unique(['document_id', 'access_level', 'source_sha256'], 'store_product_previews_document_access_source_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            if (Schema::hasIndex('store_product_previews', 'store_product_previews_document_access_source_unique')) {
                $table->dropUnique('store_product_previews_document_access_source_unique');
            }
            if (Schema::hasColumn('store_product_previews', 'document_id')) {
                $table->dropConstrainedForeignId('document_id');
            }
            if (Schema::hasColumn('store_product_previews', 'source_sha256')) {
                $table->dropColumn('source_sha256');
            }
        });
    }
};
