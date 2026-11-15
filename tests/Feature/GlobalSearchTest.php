<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_combines_public_products_articles_and_services_without_exposing_drafts(): void
    {
        $category = StoreCategory::create([
            'name' => 'منابع اسناد',
            'slug' => 'search-document-resources',
            'is_active' => true,
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'راهنمای OCR آزمایشی',
            'slug' => 'global-search-ocr-fixture',
            'sku' => 'GLOBAL-SEARCH-OCR-001',
            'type' => 'digital_file',
            'short_description' => 'راهنمای تبدیل تصویر به متن قابل جستجو',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'OCR پیش‌نویس محرمانه',
            'slug' => 'global-search-private-draft',
            'sku' => 'GLOBAL-SEARCH-OCR-DRAFT',
            'type' => 'digital_file',
            'price_rials' => 0,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->get(route('search', ['q' => 'OCR']))
            ->assertOk()
            ->assertSee('راهنمای OCR آزمایشی')
            ->assertSee('اسکن تا سند قابل ویرایش')
            ->assertSee('تایپ و ویرایش سند')
            ->assertDontSee('OCR پیش‌نویس محرمانه');
    }

    public function test_suggestions_are_grouped_and_link_only_to_public_destinations(): void
    {
        $category = StoreCategory::create([
            'name' => 'منابع دیجیتال',
            'slug' => 'search-digital-resources',
            'is_active' => true,
        ]);

        StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'راهنمای OCR آزمایشی',
            'slug' => 'global-search-suggestion-fixture',
            'sku' => 'GLOBAL-SEARCH-SUGGESTION-001',
            'type' => 'digital_file',
            'short_description' => 'راهنمای تبدیل تصویر به متن',
            'price_rials' => 0,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson(route('search.suggestions', ['q' => 'OCR']))
            ->assertOk()
            ->assertJsonPath('groups.0.key', 'products')
            ->assertJsonPath('groups.0.items.0.url', route('store.product', ['slug' => 'global-search-suggestion-fixture']))
            ->assertJsonPath('all_results_url', route('search', ['q' => 'OCR']));
    }

    public function test_short_or_malformed_queries_return_empty_suggestions(): void
    {
        $this->getJson(route('search.suggestions', ['q' => 'x']))
            ->assertOk()
            ->assertExactJson(['query' => 'x', 'groups' => [], 'all_results_url' => null]);

        $this->getJson('/search/suggestions?q[]=OCR')
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('groups', []);
    }

    public function test_public_header_search_submits_to_the_global_search_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="farastGlobalSearchForm"', false)
            ->assertSee('action="'.route('search').'"', false)
            ->assertSee('name="q"', false)
            ->assertSee('id="farastSearchSuggestions"', false);
    }
}
