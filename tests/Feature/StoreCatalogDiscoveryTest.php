<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_storefront_prioritizes_catalog_and_uses_a_compact_public_footer(): void
    {
        $this->get('/store')
            ->assertOk()
            ->assertSee('store-hero--focused', false)
            ->assertSee('aria-label="جستجوی محصولات فروشگاه"', false)
            ->assertSee('store-product-grid', false)
            ->assertSee('farast-footer-compact', false)
            ->assertSee(route('trust'), false)
            ->assertDontSee('store-feature-row', false)
            ->assertSee('farast-store-hero.svg')
            ->assertSee('store-hero-visual--brand', false)
            ->assertDontSee('store-hero--with-artwork', false)
            ->assertDontSee('store-shelf-track', false)
            ->assertDontSee('farast-footer-cta', false);
    }

    public function test_catalog_hero_uses_a_real_published_product_cover_when_available(): void
    {
        $category = StoreCategory::create([
            'name' => 'منابع تصویری',
            'slug' => 'visual-resources',
            'is_active' => true,
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'کتابچه نمونه فراست',
            'slug' => 'catalog-hero-cover-fixture',
            'sku' => 'CATALOG-HERO-001',
            'type' => 'digital_file',
            'price_rials' => 0,
            'cover_path' => 'storage/store/covers/catalog-hero.webp',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/store')
            ->assertOk()
            ->assertSee('store-hero--with-artwork', false)
            ->assertSee('store-hero-visual', false)
            ->assertSee('storage/store/covers/catalog-hero.webp', false)
            ->assertSee('کتابچه نمونه فراست')
            ->assertSee('مشاهده جزئیات و پیش‌نمایش محصول');
    }

    public function test_catalog_filters_courses_and_digital_products_without_losing_category_counts(): void
    {
        $category = StoreCategory::create([
            'name' => 'منابع دیجیتال',
            'slug' => 'digital-resources',
            'is_active' => true,
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'فایل آموزشی آزمایشی',
            'slug' => 'catalog-digital-fixture',
            'sku' => 'CATALOG-DIGITAL-001',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'دوره آموزشی آزمایشی',
            'slug' => 'catalog-course-fixture',
            'sku' => 'CATALOG-COURSE-001',
            'type' => 'course',
            'price_rials' => 250000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/store?type=digital')
            ->assertOk()
            ->assertSee('فایل آموزشی آزمایشی')
            ->assertDontSee('دوره آموزشی آزمایشی')
            ->assertSee('store-cover-placeholder', false)
            ->assertSee('فایل دیجیتال (1)', false);

        $this->get('/store?type=course')
            ->assertOk()
            ->assertSee('دوره آموزشی آزمایشی')
            ->assertDontSee('فایل آموزشی آزمایشی')
            ->assertSee('دوره آموزشی (1)', false);

        $this->get('/store?price=free&type=digital')
            ->assertOk()
            ->assertSee('فایل آموزشی آزمایشی')
            ->assertDontSee('دوره آموزشی آزمایشی');
    }

    public function test_malformed_array_query_parameters_fall_back_to_safe_catalog_defaults(): void
    {
        $this->get('/store?q[]=unexpected&price[]=free&type[]=course&sort[]=title')
            ->assertOk()
            ->assertSee('store-product-grid', false)
            ->assertDontSee('فیلترهای انتخاب‌شده');
    }
}
