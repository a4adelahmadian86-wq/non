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
 
    public function test_cart_actions_render_an_accessible_feedback_region(): void
    {
        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('id="storeCartActionFeedback"', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertDontSee('alert(err.message)', false);
    }
}
