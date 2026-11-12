<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->string('relative_path', 1024)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        DB::table('store_product_ingestions')->whereNull('product_id')->delete();
        Schema::table('store_product_ingestions', function (Blueprint $table) {
            $table->dropColumn('relative_path');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
