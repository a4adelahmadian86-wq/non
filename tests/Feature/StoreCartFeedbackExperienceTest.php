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
}
