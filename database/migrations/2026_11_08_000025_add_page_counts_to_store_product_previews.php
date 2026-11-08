<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->unsignedInteger('source_page_count')->nullable()->after('page_number');
            $table->unsignedInteger('included_page_count')->nullable()->after('source_page_count');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->dropColumn(['source_page_count', 'included_page_count']);
        });
    }
};
