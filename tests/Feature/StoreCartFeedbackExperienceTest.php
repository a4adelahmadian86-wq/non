<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCartFeedbackExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_uses_an_accessible_inline_region_for_cart_feedback(): void
    {
        $this->get(route('store'))
            ->assertOk()
            ->assertSee('id="storeCartFeedback"', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('store-cart-feedback.css', false)
            ->assertDontSee('alert(e.message)', false);
    }
}
