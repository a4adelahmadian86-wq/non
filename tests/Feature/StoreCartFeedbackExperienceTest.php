<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCartFeedbackExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_cart_explains_next_step_and_product_controls_are_accessible(): void
    {
        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('سبد خرید خالی است')
            ->assertSee('مشاهده فروشگاه')
            ->assertSee('ادامه خرید');
    }

    public function test_product_detail_has_quick_navigation_gallery_dialog_and_share_action(): void
    {
        $category = StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => 'store-detail-test',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول صفحه جزئیات',
            'slug' => 'store-detail-test-product',
            'sku' => 'STORE-DETAIL-TEST-001',
            'type' => 'digital_file',
            'price_rials' => 125000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('store.product', $product->slug))
            ->assertOk()
            ->assertSee('store-product-jump', false)
            ->assertSee('id="store-product-preview"', false)
            ->assertSee('id="store-product-description"', false)
            ->assertSee('id="store-buy-card"', false)
            ->assertSee('data-share-product', false)
            ->assertSee('store-gallery-dialog', false);
    }

    public function test_storefront_product_discovery_page_loads(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('store-product-grid', false);
    }
}
