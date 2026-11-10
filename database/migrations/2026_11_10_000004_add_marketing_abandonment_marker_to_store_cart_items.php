<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_cart_items', 'marketing_abandoned_at')) {
            Schema::table('store_cart_items', function (Blueprint $table) {
                $table->timestamp('marketing_abandoned_at')->nullable()->after('price_snapshot_rials');
                $table->index(['marketing_abandoned_at', 'updated_at'], 'store_cart_items_marketing_abandoned_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_cart_items', 'marketing_abandoned_at')) {
            Schema::table('store_cart_items', function (Blueprint $table) {
                $table->dropIndex('store_cart_items_marketing_abandoned_idx');
                $table->dropColumn('marketing_abandoned_at');
            });
        }
    }
};
