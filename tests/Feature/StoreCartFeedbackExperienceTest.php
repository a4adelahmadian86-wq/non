<?php

namespace Tests\Feature;

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

    public function test_storefront_product_discovery_page_loads(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('store-product-grid', false);
    }
}
