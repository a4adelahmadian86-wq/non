<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreProductSeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProduct(array $overrides = []): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'محصولات آزمایشی',
            'slug' => 'seo-products',
            'is_active' => true,
        ]);

        return StoreProduct::create(array_merge([
            'category_id' => $category->id,
            'title' => 'عنوان محصول',
            'slug' => 'seo-product',
            'sku' => 'SEO-PRODUCT-001',
            'type' => 'digital_file',
            'short_description' => 'توضیح کوتاه محصول',
            'description' => 'توضیح کامل محصول',
            'price_rials' => 125000,
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    public function test_product_page_uses_seo_overrides_for_title_and_description_and_gallery_image_fallback(): void
    {
        $product = $this->publishedProduct([
            'seo_title' => 'عنوان مخصوص موتور جست‌وجو',
            'seo_description' => 'توضیح اختصاصی سئو',
        ]);

        StoreProductImage::create([
            'product_id' => $product->id,
            'path' => 'storage/store/product-images/'.$product->id.'/gallery.webp',
            'alt' => 'تصویر گالری',
            'kind' => 'image',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee('<title>عنوان مخصوص موتور جست‌وجو | فروشگاه فراست</title>', false)
            ->assertSee('name="description" content="توضیح اختصاصی سئو"', false)
            ->assertSee('property="og:title" content="عنوان مخصوص موتور جست‌وجو | فروشگاه فراست"', false)
            ->assertSee('property="og:description" content="توضیح اختصاصی سئو"', false)
            ->assertSee('name="twitter:title" content="عنوان مخصوص موتور جست‌وجو | فروشگاه فراست"', false)
            ->assertSee('og:image', false)
            ->assertSee('store/product-images/'.$product->id.'/gallery.webp', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"priceCurrency":"IRR"', false)
            ->assertSee('"price":"125000"', false);
    }

    public function test_product_page_falls_back_to_product_copy_when_seo_fields_are_empty(): void
    {
        $product = $this->publishedProduct();

        $this->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee('<title>عنوان محصول | فروشگاه فراست</title>', false)
            ->assertSee('name="description" content="توضیح کوتاه محصول"', false)
            ->assertSee('property="og:description" content="توضیح کوتاه محصول"', false);
    }
}
