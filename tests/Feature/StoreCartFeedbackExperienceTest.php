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
            ->assertSee('store-gallery-dialog', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('store-product-rating', false)
            ->assertSee('هنوز امتیازی ثبت نشده');
    }

    public function test_coupon_minimum_is_checked_before_it_is_saved_to_the_cart_session(): void
    {
        $product = $this->createCartTestProduct(10000);
        StoreCoupon::create([
            'code' => 'MINIMUM20',
            'type' => 'percent',
            'value' => 20,
            'min_subtotal_rials' => 20000,
            'is_active' => true,
        ]);

        $this->postJson(route('cart.add', $product))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => 'MINIMUM20'])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'حداقل مبلغ سبد برای استفاده از این کد تخفیف رعایت نشده است.');

        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('coupon_code', null)
            ->assertJsonPath('discount_rials', 0);
    }

    public function test_coupon_is_removed_from_cart_when_it_expires_or_reaches_its_usage_limit(): void
    {
        $product = $this->createCartTestProduct(50000);
        $coupon = StoreCoupon::create([
            'code' => 'LIMITED10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $this->postJson(route('cart.add', $product))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => 'LIMITED10'])
            ->assertOk()
            ->assertJsonPath('discount_rials', 5000);

        $coupon->update(['used_count' => 1]);
        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('coupon_code', null)
            ->assertJsonPath('discount_rials', 0);
    }

    public function test_expired_coupon_cannot_be_applied_to_the_cart(): void
    {
        $product = $this->createCartTestProduct(50000);
        StoreCoupon::create([
            'code' => 'EXPIRED10',
            'type' => 'percent',
            'value' => 10,
            'ends_at' => now()->subMinute(),
            'is_active' => true,
        ]);

        $this->postJson(route('cart.add', $product))->assertOk();
        $this->postJson(route('cart.coupon'), ['code' => 'EXPIRED10'])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('coupon_code', null)
            ->assertJsonPath('discount_rials', 0);
    }

    private function createCartTestProduct(int $priceRials): StoreProduct
    {
        $category = StoreCategory::create([
            'name' => 'دسته سبد آزمایشی',
            'slug' => 'cart-coupon-test',
            'is_active' => true,
        ]);

        return StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی سبد',
            'slug' => 'cart-coupon-product-'.$priceRials,
            'sku' => 'CART-COUPON-'.$priceRials,
            'type' => 'digital_file',
            'price_rials' => $priceRials,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_cart_excludes_products_that_are_no_longer_published(): void
    {
        $product = $this->createCartTestProduct(25000);
        $this->postJson(route('cart.add', $product))->assertOk();

        $product->update(['published_at' => now()->addDay()]);

        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('count', 0)
            ->assertJsonPath('subtotal_rials', 0);
    }

    public function test_cart_quantity_updates_return_consistent_totals_for_live_refresh(): void
    {
        $product = $this->createCartTestProduct(125000);
        $this->postJson(route('cart.add', $product))->assertOk();

        $this->postJson(route('cart.update', $product), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('items.0.quantity', 3)
            ->assertJsonPath('items.0.line_total_rials', 375000)
            ->assertJsonPath('subtotal_rials', 375000)
            ->assertJsonPath('subtotal_toman', 37500)
            ->assertJsonPath('total_toman', 37500);

        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('data-cart-subtotal', false)
            ->assertSee('data-cart-discount', false)
            ->assertSee('data-cart-total', false)
            ->assertSee('data-cart-count', false);
    }

    public function test_storefront_product_discovery_page_loads(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('store-product-grid', false);
    }
}
