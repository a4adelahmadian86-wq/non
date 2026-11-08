<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->string('access_level', 20)->default('sample')->after('kind');
            $table->index(['product_id', 'access_level', 'is_active'], 'store_previews_access_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_previews', function (Blueprint $table) {
            $table->dropIndex('store_previews_access_idx');
            $table->dropColumn('access_level');
        });
    }
};
