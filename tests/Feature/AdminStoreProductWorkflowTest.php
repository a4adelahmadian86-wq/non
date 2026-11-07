<?php

namespace Tests\Feature;

use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoreProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Store Admin',
            'mobile' => '09123456789',
            'password' => 'secret',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function category(): StoreCategory
    {
        return StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => 'test-products',
            'is_active' => true,
        ]);
    }

    private function payload(StoreCategory $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'محصول آزمایشی',
            'sku' => 'TEST-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 125000,
            'status' => 'draft',
            'short_description' => 'توضیح کوتاه',
        ], $overrides);
    }

    public function test_new_products_are_always_created_as_private_drafts_and_cover_path_is_not_mass_assignable(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $response = $this->actingAs($admin)->post(
            route('admin.store.store'),
            $this->payload($category, [
                'status' => 'published',
                'cover_path' => 'https://attacker.invalid/fake-cover.jpg',
            ])
        );

        $product = StoreProduct::query()->where('sku', 'TEST-PRODUCT-001')->firstOrFail();

        $response->assertRedirect(route('admin.store.edit', $product));
        $this->assertSame('draft', $product->status);
        $this->assertNull($product->published_at);
        $this->assertNull($product->cover_path);
    }

    public function test_compare_at_price_must_be_greater_than_the_sale_price(): void
    {
        $admin = $this->admin();
        $category = $this->category();

        $this->actingAs($admin)
            ->from(route('admin.store.create'))
            ->post(route('admin.store.store'), $this->payload($category, [
                'compare_at_price_rials' => 125000,
            ]))
            ->assertRedirect(route('admin.store.create'))
            ->assertSessionHasErrors('compare_at_price_rials');

        $this->assertDatabaseMissing('store_products', ['sku' => 'TEST-PRODUCT-001']);
    }

    public function test_published_file_product_requires_an_active_clean_primary_file_on_every_update(): void
    {
        $admin = $this->admin();
        $category = $this->category();
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول پیش‌نویس',
            'slug' => 'draft-product',
            'sku' => 'DRAFT-PRODUCT-001',
            'type' => 'digital_file',
            'price_rials' => 100000,
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.store.edit', $product))
            ->put(route('admin.store.update', $product), $this->payload($category, [
                'title' => $product->title,
                'sku' => $product->sku,
                'status' => 'published',
            ]))
            ->assertRedirect(route('admin.store.edit', $product))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
    }
}
