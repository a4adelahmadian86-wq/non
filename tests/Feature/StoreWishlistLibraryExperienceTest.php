<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\StoreWishlist;
use App\Models\StoreLibraryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreWishlistLibraryExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wishlist_lists_are_switchable_and_items_can_be_removed_from_the_selected_list(): void
    {
        $user = User::create([
            'name' => 'Wishlist QA',
            'mobile' => '09123456789',
            'password' => 'secret',
        ]);
        $category = StoreCategory::create([
            'name' => 'دسته آزمایشی',
            'slug' => 'wishlist-library-test',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'محصول ذخیره‌شده',
            'slug' => 'wishlist-library-product',
            'sku' => 'WISHLIST-LIBRARY-001',
            'type' => 'digital_file',
            'price_rials' => 125000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        StoreWishlist::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'list_type' => 'wishlist',
        ]);

        $this->actingAs($user)
            ->get(route('wishlist'))
            ->assertOk()
            ->assertSee('wishlist-tabs', false)
            ->assertSee('محصول ذخیره‌شده')
            ->assertSee('حذف از این فهرست');

        $this->actingAs($user)
            ->get(route('wishlist', ['type' => 'later']))
            ->assertOk()
            ->assertSee('بعداً می‌خرم')
            ->assertSee('هنوز محصولی برای بعداً ذخیره نکرده‌اید');

        $this->actingAs($user)
            ->delete(route('wishlist.destroy', $product), ['list_type' => 'wishlist'])
            ->assertRedirect();

        $this->assertDatabaseMissing('store_wishlists', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'list_type' => 'wishlist',
        ]);
    }

    public function test_library_has_a_page_scoped_quick_search_without_changing_download_controls(): void
    {
        $user = User::create([
            'name' => 'Library QA',
            'mobile' => '09123456788',
            'password' => 'secret',
        ]);
        $category = StoreCategory::create([
            'name' => 'دسته کتابخانه',
            'slug' => 'library-search-test',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'title' => 'فایل کتابخانه آزمایشی',
            'slug' => 'library-search-product',
            'sku' => 'LIBRARY-SEARCH-001',
            'type' => 'digital_file',
            'price_rials' => 50000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'document_id' => null,
            'subtotal_rials' => 50000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 50000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now(),
        ]);
        StoreLibraryItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'product_file_id' => null,
            'granted_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('library'))
            ->assertOk()
            ->assertSee('libraryQuickSearch', false)
            ->assertSee('جست‌وجو در این صفحه')
            ->assertSee('libraryDownloadFeedback', false);
    }
}
