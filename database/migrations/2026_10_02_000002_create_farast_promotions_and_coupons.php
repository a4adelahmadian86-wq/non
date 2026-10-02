<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if(!Schema::hasTable('farast_promotions')){
            Schema::create('farast_promotions',function(Blueprint $table){
                $table->id();$table->string('code',100)->unique();$table->string('name',180);
                $table->string('discount_type',20)->default('percent');$table->unsignedBigInteger('discount_value')->default(0);
                $table->unsignedBigInteger('max_discount')->nullable();$table->string('capability_code',120)->nullable()->index();
                $table->timestamp('starts_at')->nullable();$table->timestamp('ends_at')->nullable();
                $table->unsignedInteger('usage_limit')->nullable();$table->boolean('active')->default(true);
                $table->json('metadata')->nullable();$table->timestamps();
            });
        }
        if(!Schema::hasTable('farast_coupons')){
            Schema::create('farast_coupons',function(Blueprint $table){
                $table->id();$table->string('code',120)->unique();$table->string('promotion_code',100)->nullable()->index();
                $table->string('discount_type',20)->default('percent');$table->unsignedBigInteger('discount_value')->default(0);
                $table->unsignedBigInteger('max_discount')->nullable();$table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('per_actor_limit')->default(1);$table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();$table->boolean('active')->default(true);$table->json('metadata')->nullable();$table->timestamps();
            });
        }
        if(!Schema::hasTable('farast_coupon_redemptions')){
            Schema::create('farast_coupon_redemptions',function(Blueprint $table){
                $table->id();$table->string('coupon_code',120)->index();$table->unsignedBigInteger('actor_id')->index();
                $table->unsignedBigInteger('charge_id')->nullable()->index();$table->string('idempotency_key',180)->unique();
                $table->unsignedBigInteger('discount_amount')->default(0);$table->string('currency',8)->default('IRR');
                $table->timestamps();$table->index(['coupon_code','actor_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farast_coupon_redemptions');
        Schema::dropIfExists('farast_coupons');
        Schema::dropIfExists('farast_promotions');
    }
};