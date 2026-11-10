<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_coupon_assignments')) {
            Schema::create('store_coupon_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_id')->unique()->constrained('store_coupons')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('store_products')->nullOnDelete();
                $table->string('status', 20)->default('offered');
                $table->unsignedSmallInteger('score')->default(0);
                $table->json('reason_codes');
                $table->timestamp('offered_at');
                $table->timestamp('expires_at');
                $table->timestamp('redeemed_at')->nullable();
                $table->foreignId('notification_id')->nullable()->constrained('farast_notifications')->nullOnDelete();
                $table->timestamps();
                $table->index(['user_id', 'offered_at']);
                $table->index(['user_id', 'product_id', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_coupon_assignments');
    }
};
