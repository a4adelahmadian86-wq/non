<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 80)->nullable();
            $table->string('cover_path')->nullable();
            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['parent_id', 'is_active', 'sort_order']);
        });

        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('store_categories')->restrictOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 220);
            $table->string('slug', 240)->unique();
            $table->string('sku', 80)->unique();
            $table->string('type', 40)->default('digital_file');
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedBigInteger('price_rials')->default(0);
            $table->unsignedBigInteger('compare_at_price_rials')->nullable();
            $table->string('tax_class', 60)->default('standard');
            $table->string('status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('seo_title', 220)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('cover_path')->nullable();
            $table->string('preview_policy', 40)->default('limited');
            $table->unsignedInteger('preview_pages')->default(3);
            $table->string('download_policy', 40)->default('signed');
            $table->string('license_type', 60)->default('standard');
            $table->string('version', 60)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['category_id', 'status', 'published_at']);
            $table->index(['featured', 'status', 'sort_order']);
            $table->index(['seller_id', 'status']);
        });

        Schema::create('store_product_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('version', 60)->nullable();
            $table->string('disk', 60)->default('private');
            $table->string('path');
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
            $table->index('sha256');
        });

        Schema::create('store_product_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('disk', 60)->default('private');
            $table->string('path');
            $table->string('mime', 180)->nullable();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('kind', 40)->default('image');
            $table->boolean('watermarked')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        Schema::create('store_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('path');
            $table->string('alt', 220)->nullable();
            $table->string('kind', 40)->default('gallery');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        Schema::create('store_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });

        Schema::create('store_product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('store_tags')->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });

        Schema::create('store_product_related', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->primary(['product_id', 'related_product_id']);
        });

        Schema::create('store_product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 180)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamps();
            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'status']);
        });

        Schema::create('store_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('code', 100)->unique();
            $table->string('name', 160);
            $table->text('terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_licenses');
        Schema::dropIfExists('store_product_reviews');
        Schema::dropIfExists('store_product_related');
        Schema::dropIfExists('store_product_tag');
        Schema::dropIfExists('store_tags');
        Schema::dropIfExists('store_product_images');
        Schema::dropIfExists('store_product_previews');
        Schema::dropIfExists('store_product_files');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_categories');
    }
};
