<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_coupons', function (Blueprint $table) {
            $table->unsignedInteger('reserved_count')->default(0)->after('used_count');
        });

        Schema::create('store_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('store_coupons')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('discount_rials')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at'], 'store_coupon_redemption_expiry_idx');
            $table->index(['coupon_id', 'status'], 'store_coupon_redemption_coupon_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupon_redemptions');

        Schema::table('store_coupons', function (Blueprint $table) {
            $table->dropColumn('reserved_count');
        });
    }
};
