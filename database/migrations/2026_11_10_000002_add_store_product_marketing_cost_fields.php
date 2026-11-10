<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_products', 'cost_price_rials')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->unsignedBigInteger('cost_price_rials')->nullable()->after('price_rials');
                $table->unsignedBigInteger('marketing_min_margin_rials')->default(0)->after('cost_price_rials');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_products', 'marketing_min_margin_rials')) {
            Schema::table('store_products', fn (Blueprint $table) => $table->dropColumn('marketing_min_margin_rials'));
        }
        if (Schema::hasColumn('store_products', 'cost_price_rials')) {
            Schema::table('store_products', fn (Blueprint $table) => $table->dropColumn('cost_price_rials'));
        }
    }
};
