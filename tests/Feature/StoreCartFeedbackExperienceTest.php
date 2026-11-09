<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCartFeedbackExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_product_discovery_page_loads(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('store-product-grid', false);
    }

    public function test_cart_feedback_is_announced_accessibly_without_browser_alerts(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('id="storeCartFeedback"', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('.store-cart-feedback{', false)
            ->assertDontSee('alert(e.message)', false);
    }
}
