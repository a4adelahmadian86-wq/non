<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_coupon_assignments') && ! Schema::hasColumn('store_coupon_assignments', 'redeemed_order_id')) {
            Schema::table('store_coupon_assignments', function (Blueprint $table) {
                $table->foreignId('redeemed_order_id')->nullable()->unique()->after('redeemed_at')
                    ->constrained('orders')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_coupon_assignments', 'redeemed_order_id')) {
            Schema::table('store_coupon_assignments', function (Blueprint $table) {
                $table->dropForeign(['redeemed_order_id']);
                $table->dropUnique(['redeemed_order_id']);
                $table->dropColumn('redeemed_order_id');
            });
        }
    }
};
