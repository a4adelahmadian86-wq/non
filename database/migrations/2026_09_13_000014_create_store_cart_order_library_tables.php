<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->string('session_token', 96)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('store_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('store_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('price_snapshot_rials');
            $table->timestamps();
            $table->unique(['cart_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('store_products')->nullOnDelete();
            $table->string('title_snapshot', 220);
            $table->string('sku_snapshot', 80)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_rials');
            $table->unsignedBigInteger('discount_rials')->default(0);
            $table->unsignedBigInteger('tax_rials')->default(0);
            $table->unsignedBigInteger('total_rials');
            $table->json('product_snapshot')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'product_id']);
        });

        Schema::create('store_library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('store_order_items')->nullOnDelete();
            $table->foreignId('product_file_id')->nullable()->constrained('store_product_files')->nullOnDelete();
            $table->string('license_code', 100)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id', 'order_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('store_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_item_id')->constrained('store_library_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_file_id')->constrained('store_product_files')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('download_count')->default(0);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['library_item_id', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_downloads');
        Schema::dropIfExists('store_library_items');
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_cart_items');
        Schema::dropIfExists('store_carts');
    }
};
