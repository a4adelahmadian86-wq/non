<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('list_type', 20)->default('wishlist');
            $table->timestamps();
            $table->unique(['user_id', 'product_id', 'list_type']);
            $table->index(['user_id', 'list_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_wishlists');
    }
};
