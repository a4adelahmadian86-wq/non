<?php

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class StoreCatalogDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_accessible_categories_and_clearable_active_filters(): void
    {
        $response = $this->get('/store?q=نمونه&price=free&sort=title');

        $response->assertOk()
            ->assertSee('aria-label="دسته‌بندی محصولات"', false)
            ->assertSee('store-active-filters', false)
            ->assertSee('فیلترهای انتخاب‌شده')
            ->assertSee('نمونه')
            ->assertSee('فقط رایگان')
            ->assertSee('پاک‌کردن فیلترها')
            ->assertSee('name="sort"', false)
            ->assertSee('name="price"', false);
    }
}
